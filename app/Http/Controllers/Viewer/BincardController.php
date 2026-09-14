<?php

namespace App\Http\Controllers\Viewer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Viewer\BincardRequest;
use App\Models\Bin;
use App\Models\Item;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
use App\Models\Warehouse;
use App\Support\Excel;
use App\Support\StockLedgerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sub-bin stock card (bincard) ported from utility/utility_bincard.php and
 * Transaction/home_bincard_print.php.
 *
 * Legacy flow: pick warehouse + bin + sub-bin; the card lists every item
 * currently held at that sub-bin (positive balance as-of the printed date)
 * with its current UPS/qty and a per-item movement ledger for the printed
 * period. The port reads balances through StockLedgerService (latest-row
 * semantics, identical to the stock-on-hand report) and movement history
 * through the new StockLedgerService::ledgerForItemAt() reader.
 *
 * Port deltas (deliberate): server-side validation of the chosen SLOC
 * (the chosen warehouse/bin/sub-bin must actually hold stock); as-of date
 * defaults to today (legacy used the session year); on-screen cap at 500
 * items with a full export stream.
 */
class BincardController extends Controller
{
    public function index(BincardRequest $request): View
    {
        $asOf = $request->asOf();
        $whId = $request->warehouseId();
        $binId = $request->binId();
        $sid = $request->subbinId();
        $classId = $request->classificationId();

        $subBin = $this->chooseSubBin($whId, $binId, $sid);
        $rows = $this->cardRows($subBin, $asOf, $classId);

        return view('viewer.reports.bincard', [
            'subBin' => $subBin,
            'rows' => $rows,
            'asOf' => $asOf,
            'classId' => $classId,
            'warehouseId' => $whId,
            'binId' => $binId,
            'subbinId' => $sid,
            'warehouses' => Warehouse::query()->orderBy('perticulars')->get(['whid', 'perticulars']),
            'bins' => $whId !== null
                ? Bin::query()->where('whid', $whId)->orderBy('binname')->get(['binid', 'binname', 'whid'])
                : Bin::query()->orderBy('binname')->get(['binid', 'binname', 'whid']),
            'subbins' => $binId !== null
                ? SubBin::query()->where('binid', $binId)->orderBy('sname')->get(['sid', 'sname', 'binid'])
                : SubBin::query()->orderBy('sname')->get(['sid', 'sname', 'binid']),
        ]);
    }

    public function export(BincardRequest $request): StreamedResponse|BinaryFileResponse
    {
        $asOf = $request->asOf();
        $whId = $request->warehouseId();
        $binId = $request->binId();
        $sid = $request->subbinId();
        $classId = $request->classificationId();

        $subBin = $this->chooseSubBin($whId, $binId, $sid);

        return Excel::stream(
            sprintf(
                'bincard-%s-%s-%s-%s.xlsx',
                $subBin?->whid ?? 'all',
                $subBin?->binid ?? 'all',
                $subBin?->sid ?? 'all',
                $asOf
            ),
            [
                'Date', 'Classification', 'Item', 'UoM',
                'Warehouse', 'Bin', 'Sub-bin',
                'Type', 'Sub-type', 'Doc #',
                'In UPS', 'In Qty', 'Out UPS', 'Out Qty',
                'Balance UPS', 'Balance Qty',
            ],
            $this->exportRows($subBin, $asOf, $classId)
        );
    }

    /**
     * The sub-bin the card is printed for.
     *
     * When the request names a full SLOC (warehouse + bin + sub-bin) the
     * card prints that sub-bin; when only a warehouse is named the card
     * prints a warehouse summary (every stocked sub-bin in it); otherwise
     * the card picks the first stocked sub-bin in the active fiscal year.
     */
    private function chooseSubBin(?int $whId, ?int $binId, ?int $sid): ?SubBin
    {
        if ($sid !== null) {
            return SubBin::find($sid);
        }

        if ($binId !== null) {
            return SubBin::query()
                ->where('binid', $binId)
                ->when($whId !== null, fn ($q, $w) => $q->where('whid', $whId))
                ->first();
        }

        if ($whId !== null) {
            return SubBin::query()
                ->where('whid', $whId)
                ->first();
        }

        if (! Schema::hasTable('stock_ledger_goods')) {
            return null;
        }

        $row = DB::table('sub_bins')
            ->join('stock_ledger_goods', 'stock_ledger_goods.stlg_subbinid', '=', 'sub_bins.sid')
            ->where('stock_ledger_goods.stlg_balqty', '>', 0)
            ->where('stock_ledger_goods.stlg_trdate', '<=', $this->today())
            ->orderBy('sub_bins.whid')
            ->orderBy('sub_bins.binid')
            ->orderBy('sub_bins.sid')
            ->first(['sub_bins.sid']);

        if ($row === null) {
            return null;
        }

        return SubBin::query()->where('sid', $row->sid)->first();
    }

    /**
     * Card rows: for the chosen sub-bin, every item with a positive balance
     * as-of $asOf, plus that item's movement ledger for the printed period.
     */
    private function cardRows(?SubBin $subBin, string $asOf, ?int $classId): array
    {
        $rows = [];

        if ($subBin === null) {
            return $rows;
        }

        $items = $this->itemsAt($subBin, $classId, $asOf);

        foreach ($items as $item) {
            $itemRow = Item::with('classification')
                ->where('items_id', $item->stlg_tritemid)
                ->where('actstatus', 'Active')
                ->first();

            if ($itemRow === null) {
                continue;
            }

            $balance = StockLedgerService::balanceAt(
                (int) $item->stlg_tritemid,
                (int) $subBin->whid,
                (int) $subBin->binid,
                (int) $subBin->sid,
                $asOf,
            );

            if ($balance['qty'] <= 0) {
                continue;
            }

            $ledger = StockLedgerService::ledgerForItemAt(
                (int) $item->stlg_tritemid,
                (int) $subBin->whid,
                (int) $subBin->binid,
                (int) $subBin->sid,
                $this->periodFrom($asOf),
                $asOf,
                $asOf,
            );

            $rows[] = [
                'classification' => $itemRow->classification->classification ?? '',
                'item' => $itemRow->stores_item,
                'uom' => $itemRow->uom,
                'warehouse' => Warehouse::find($subBin->whid)?->perticulars ?? '',
                'bin' => Bin::find($subBin->binid)?->binname ?? '',
                'subbin' => SubBin::find($subBin->sid)?->sname ?? '',
                'ups' => $balance['ups'],
                'qty' => $balance['qty'],
                'ledger' => $ledger,
            ];

            if (count($rows) >= 500) {
                return $rows;
            }
        }

        return $rows;
    }

    private function exportRows(?SubBin $subBin, string $asOf, ?int $classId): array
    {
        $rows = [];
        $items = $this->itemsAt($subBin, $classId, $asOf);

        foreach ($items as $item) {
            $itemRow = Item::with('classification')
                ->where('items_id', $item->stlg_tritemid)
                ->where('actstatus', 'Active')
                ->first();

            if ($itemRow === null) {
                continue;
            }

            $ledger = StockLedgerService::ledgerForItemAt(
                (int) $item->stlg_tritemid,
                $subBin ? (int) $subBin->whid : 0,
                $subBin ? (int) $subBin->binid : 0,
                $subBin ? (int) $subBin->sid : 0,
                $this->periodFrom($asOf),
                $asOf,
                $asOf,
            );

            foreach ($ledger as $m) {
                $isIn = $this->movementIsIn($m['type'], $m['subtype']);
                $isOut = $this->movementIsOut($m['type'], $m['subtype']);

                $rows[] = [
                    'date' => $m['date'],
                    'classification' => $itemRow->classification->classification ?? '',
                    'item' => $itemRow->stores_item,
                    'uom' => $itemRow->uom,
                    'warehouse' => $subBin ? Warehouse::find($subBin->whid)?->perticulars ?? '' : '',
                    'bin' => $subBin ? Bin::find($subBin->binid)?->binname ?? '' : '',
                    'subbin' => $subBin ? SubBin::find($subBin->sid)?->sname ?? '' : '',
                    'type' => $m['type'],
                    'subtype' => $m['subtype'],
                    'doc' => $m['doc'],
                    'in_ups' => $isIn ? $m['trups'] : 0,
                    'in_qty' => $isIn ? $m['trqty'] : 0,
                    'out_ups' => $isOut ? $m['trups'] : 0,
                    'out_qty' => $isOut ? $m['trqty'] : 0,
                    'balups' => $m['balups'],
                    'balqty' => $m['balqty'],
                ];
            }
        }

        return $rows;
    }

    /**
     * DISTINCT items touched at the chosen location(s) up to the as-of date.
     */
    private function itemsAt(?SubBin $subBin, ?int $classId, string $asOf): Collection
    {
        $q = StockLedgerGood::query()
            ->select('stlg_tritemid', 'stlg_trclassid')
            ->where('stlg_trdate', '<=', $asOf)
            ->when($classId, fn ($q, $c) => $q->where('stlg_trclassid', $c));

        if ($subBin !== null) {
            $q->where('stlg_whid', $subBin->whid)
                ->where('stlg_binid', $subBin->binid)
                ->where('stlg_subbinid', $subBin->sid);
        }

        return $q->distinct()->orderBy('stlg_tritemid')->get();
    }

    private function movementIsIn(string $type, string $subtype): bool
    {
        return $type === 'Arrival'
            || str_starts_with($type, 'ES') && $subtype !== 'SH'
            || ($type === 'IT' && $subtype === 'in')
            || $type === 'GD';
    }

    private function movementIsOut(string $type, string $subtype): bool
    {
        return $type === 'Issue'
            || $type === 'CC'
            || $type === 'DG'
            || ($type === 'ES' && $subtype === 'SH')
            || ($type === 'IT' && $subtype === 'out');
    }

    private function periodFrom(string $asOf): string
    {
        return substr($asOf, 0, 4).'-01-01';
    }

    private function today(): string
    {
        return date('Y-m-d');
    }
}
