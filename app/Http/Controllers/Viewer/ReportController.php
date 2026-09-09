<?php

namespace App\Http\Controllers\Viewer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Viewer\LedgerRequest;
use App\Http\Requests\Viewer\StockOnHandRequest;
use App\Models\Bin;
use App\Models\Item;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
use App\Models\Warehouse;
use App\Support\Excel;
use App\Support\StockLedgerService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Viewer reports ported from reports1/viwerreports.php catalogue:
 *   - Stock On Hand        (report_stockhand.php)
 *   - Stores Item Ledger   (report_itemledger.php)
 *   - Stock Transfer       (stocktransferreport1.php)
 *
 * Legacy logic preserved: latest-row-per-location balance as-of date; ledger
 * rows summarized by type/subtype/document; party-scoped movement listing.
 */
class ReportController extends Controller
{
    public function index(): View
    {
        $reports = [
            ['code' => 'stock-on-hand', 'label' => 'Stock On Hand (Good)', 'live' => true],
            ['code' => 'item-ledger', 'label' => 'Stores Item Ledger', 'live' => true],
            ['code' => 'stock-transfer', 'label' => 'Stock Transfer (Party-wise)', 'live' => true],
            ['code' => 'stock-on-hand-damage', 'label' => 'Stock On Hand (Damage)', 'live' => false],
            ['code' => 'consumption', 'label' => 'Consumption (Item-wise)', 'live' => false],
            ['code' => 'discard', 'label' => 'Discard Report', 'live' => false],
            ['code' => 'partywise', 'label' => 'Party-wise Period', 'live' => false],
            ['code' => 'reorder', 'label' => 'Reorder Level', 'live' => false],
        ];

        return view('viewer.reports.index', ['reports' => $reports]);
    }

    public function stockOnHand(StockOnHandRequest $request): View
    {
        $asOf = $request->asOf();
        $classificationId = $request->classificationId();

        $rows = $this->stockOnHandRows($asOf, $classificationId);

        return view('viewer.reports.stock-on-hand', [
            'rows' => $rows,
            'asOf' => $asOf,
            'classificationId' => $classificationId,
        ]);
    }

    public function stockOnHandExport(StockOnHandRequest $request): StreamedResponse|BinaryFileResponse
    {
        $asOf = $request->asOf();
        $classificationId = $request->classificationId();

        return Excel::stream(
            'stock-on-hand-'.$asOf.'.xlsx',
            ['Classification', 'Item', 'UoM', 'Warehouse', 'Bin', 'Sub-bin', 'UPS', 'Qty'],
            $this->stockOnHandRows($asOf, $classificationId, forExport: true)
        );
    }

    private function stockOnHandRows(string $asOf, ?int $classificationId, bool $forExport = false): array
    {
        // Legacy parity (report_stockhand.php):
        //   1. DISTINCT items touched up to the as-of date (optionally by class).
        //   2. Per item: DISTINCT locations with activity up to as-of.
        //   3. Per item x location: balance of the MAX(stlg_id) row <= as-of
        //      where stlg_balqty > 0.
        $itemsQuery = StockLedgerGood::query()
            ->select('stlg_tritemid', 'stlg_trclassid')
            ->where('stlg_trdate', '<=', $asOf)
            ->when($classificationId, fn ($q, $c) => $q->where('stlg_trclassid', $c))
            ->distinct();

        $rows = [];
        $items = $itemsQuery->orderBy('stlg_tritemid')->get();

        foreach ($items as $item) {
            $itemRow = Item::with('classification')
                ->where('items_id', $item->stlg_tritemid)
                ->where('actstatus', 'Active')
                ->first();

            if ($itemRow === null) {
                continue; // legacy skipped inactive/missing items
            }

            $locations = StockLedgerGood::query()
                ->select('stlg_whid', 'stlg_binid', 'stlg_subbinid')
                ->where('stlg_trclassid', $item->stlg_trclassid)
                ->where('stlg_tritemid', $item->stlg_tritemid)
                ->where('stlg_trdate', '<=', $asOf)
                ->distinct()
                ->get();

            foreach ($locations as $loc) {
                $balance = StockLedgerService::balanceAt(
                    (int) $item->stlg_tritemid,
                    (int) $loc->stlg_whid,
                    (int) $loc->stlg_binid,
                    (int) $loc->stlg_subbinid,
                    $asOf
                );

                if ($balance['qty'] <= 0) {
                    continue; // legacy: only positive balances listed
                }

                $rows[] = [
                    'classification' => $itemRow->classification->classification ?? '',
                    'item' => $itemRow->stores_item,
                    'uom' => $itemRow->uom,
                    'warehouse' => Warehouse::find($loc->stlg_whid)?->perticulars ?? '',
                    'bin' => Bin::find($loc->stlg_binid)?->binname ?? '',
                    'subbin' => SubBin::find($loc->stlg_subbinid)?->sname ?? '',
                    'ups' => $balance['ups'],
                    'qty' => $balance['qty'],
                ];

                if (! $forExport && count($rows) >= 500) {
                    return $rows; // on-screen cap; exports stream everything
                }
            }
        }

        return $rows;
    }

    public function itemLedger(LedgerRequest $request): View
    {
        [$from, $to, $classificationId, $itemId] = $request->filters();

        $rows = $this->ledgerRows($from, $to, $classificationId, $itemId);

        return view('viewer.reports.item-ledger', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'classificationId' => $classificationId,
            'itemId' => $itemId,
        ]);
    }

    public function itemLedgerExport(LedgerRequest $request): StreamedResponse|BinaryFileResponse
    {
        [$from, $to, $classificationId, $itemId] = $request->filters();

        return Excel::stream(
            'item-ledger-'.($itemId ?: 'all').'.xlsx',
            ['Date', 'Type', 'Sub-type', 'Doc #', 'UPS', 'Qty', 'Balance UPS', 'Balance Qty'],
            $this->ledgerRows($from, $to, $classificationId, $itemId, forExport: true)
        );
    }

    private function ledgerRows(string $from, string $to, ?int $classificationId, ?int $itemId, bool $forExport = false): array
    {
        // Legacy parity (report_itemledger.php): group ledger rows by
        // date x type x subtype x document for the selected item/class,
        // summing movement and balance columns.
        $q = StockLedgerGood::query()
            ->selectRaw('stlg_trdate, stlg_trtype, stlg_trsubtype, stlg_trid,
                SUM(stlg_trups) AS trups, SUM(stlg_trqty) AS trqty,
                SUM(stlg_opups) AS opups, SUM(stlg_opqty) AS opqty,
                SUM(stlg_balups) AS balups, SUM(stlg_balqty) AS balqty')
            ->whereBetween('stlg_trdate', [$from, $to])
            ->when($classificationId, fn ($q, $c) => $q->where('stlg_trclassid', $c))
            ->when($itemId, fn ($q, $i) => $q->where('stlg_tritemid', $i))
            ->groupBy('stlg_trdate', 'stlg_trtype', 'stlg_trsubtype', 'stlg_trid')
            ->orderBy('stlg_trdate');

        $rows = [];
        foreach ($q->get() as $r) {
            $rows[] = [
                'date' => (string) $r->stlg_trdate,
                'type' => $r->stlg_trtype,
                'subtype' => $r->stlg_trsubtype,
                'doc' => $r->stlg_trid,
                'ups' => (int) $r->trups,
                'qty' => (float) $r->trqty,
                'balups' => (int) $r->balups,
                'balqty' => (float) $r->balqty,
            ];
            if (! $forExport && count($rows) >= 500) {
                break;
            }
        }

        return $rows;
    }

    public function stockTransfer(LedgerRequest $request): View
    {
        [$from, $to] = $request->filters();
        $partyId = (int) $request->query('party_id', 0);

        $rows = $this->transferRows($from, $to, $partyId);

        return view('viewer.reports.stock-transfer', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'partyId' => $partyId,
        ]);
    }

    public function stockTransferExport(LedgerRequest $request): StreamedResponse|BinaryFileResponse
    {
        [$from, $to] = $request->filters();
        $partyId = (int) $request->query('party_id', 0);

        return Excel::stream(
            'stock-transfer-'.($partyId ?: 'all').'.xlsx',
            ['Date', 'Type', 'Sub-type', 'Doc #', 'Item', 'UPS', 'Qty'],
            $this->transferRows($from, $to, $partyId, forExport: true)
        );
    }

    private function transferRows(string $from, string $to, int $partyId, bool $forExport = false): array
    {
        // Legacy parity (stocktransferreport1.php): party-scoped ledger rows
        // in the period, ordered by date.
        $q = StockLedgerGood::query()
            ->whereBetween('stlg_trdate', [$from, $to])
            ->when($partyId > 0, fn ($q) => $q->where('stlg_trpartyid', (string) $partyId))
            ->orderBy('stlg_trdate');

        $rows = [];
        foreach ($q->get() as $r) {
            $rows[] = [
                'date' => (string) $r->stlg_trdate,
                'type' => $r->stlg_trtype,
                'subtype' => $r->stlg_trsubtype,
                'doc' => $r->stlg_trid,
                'item' => Item::find($r->stlg_tritemid)?->stores_item ?? '',
                'ups' => (int) $r->stlg_trups,
                'qty' => (float) $r->stlg_trqty,
            ];
            if (! $forExport && count($rows) >= 500) {
                break;
            }
        }

        return $rows;
    }
}
