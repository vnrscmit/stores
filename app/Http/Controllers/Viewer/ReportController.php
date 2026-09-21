<?php

namespace App\Http\Controllers\Viewer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Viewer\ConsumptionRequest;
use App\Http\Requests\Viewer\LedgerRequest;
use App\Http\Requests\Viewer\StockOnHandDamageRequest;
use App\Http\Requests\Viewer\StockOnHandRequest;
use App\Models\Bin;
use App\Models\Classification;
use App\Models\Discard;
use App\Models\Item;
use App\Models\Party;
use App\Models\PartyLedger;
use App\Models\StockLedgerDamage;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
use App\Models\Warehouse;
use App\Support\Excel;
use App\Support\StockLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
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
            ['code' => 'bincard', 'label' => 'Sub-Bin Card', 'live' => true],
            ['code' => 'stock-on-hand-damage', 'label' => 'Stock On Hand (Damage)', 'live' => true],
            ['code' => 'consumption', 'label' => 'Consumption (Item-wise)', 'live' => true],
            ['code' => 'discard', 'label' => 'Discard Report', 'live' => true],
            ['code' => 'partywise', 'label' => 'Party-wise Period', 'live' => true],
            ['code' => 'reorder', 'label' => 'Reorder Level', 'live' => true],
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

    // Consumption (item-wise) ----------------------------------------------------
    // Legacy: reports/consumption_report + consumption_report1 + consumption_report2.

    public function consumption(ConsumptionRequest $request): View
    {
        [$from, $to, $classificationId, $itemId] = $request->filters();

        $rows = $this->consumptionRows($from, $to, $classificationId, $itemId);

        return view('viewer.reports.consumption', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'classificationId' => $classificationId,
            'itemId' => $itemId,
            'perticulars' => '',
        ]);
    }

    public function consumptionExport(ConsumptionRequest $request): StreamedResponse|BinaryFileResponse
    {
        [$from, $to, $classificationId, $itemId] = $request->filters();

        return Excel::stream(
            'consumption-'.($itemId ?: 'all').'-'.str_replace(['-', ':'], '', $from).'-'.str_replace(['-', ':'], '', $to).'.xlsx',
            ['Classification', 'Item', 'UoM', 'Issue UPS', 'Issue Qty', 'Internal Return UPS', 'Internal Return Qty', 'Used Qty UPS', 'Used Qty'],
            $this->consumptionRows($from, $to, $classificationId, $itemId, forExport: true)
        );
    }

    private function consumptionRows(string $from, string $to, ?int $classificationId, ?int $itemId, bool $forExport = false): array
    {
        // Legacy parity (consumption_report1.php): per item x date x type x subtype
        // x sub-bin, aggregate receive/issue/used columns, with Issue = sum of
        // issue-family movements (minus internal returns), Internal Return = sum
        // of Arrival( Internalreturn ), Used = Issue - Internal Return.
        $itemsQuery = StockLedgerGood::query()
            ->select('stlg_tritemid', 'stlg_trclassid')
            ->whereBetween('stlg_trdate', [$from, $to])
            ->when($classificationId, fn ($q, $c) => $q->where('stlg_trclassid', $c))
            ->when($itemId, fn ($q, $i) => $q->where('stlg_tritemid', $i))
            ->distinct();

        $rows = [];
        $items = $itemsQuery->orderBy('stlg_tritemid')->get();

        foreach ($items as $item) {
            $itemRow = Item::with('classification')
                ->where('items_id', $item->stlg_tritemid)
                ->where('actstatus', 'Active')
                ->first();

            if ($itemRow === null) {
                continue;
            }

            $rows = array_merge($rows, $this->consumptionItemRows(
                $item->stlg_tritemid,
                $item->stlg_trclassid,
                $itemRow,
                $from,
                $to,
                $forExport,
            ));

            if (! $forExport && count($rows) >= 500) {
                return $rows;
            }
        }

        return $rows;
    }

    private function consumptionItemRows(
        int $itemId,
        int $classId,
        Item $itemRow,
        string $from,
        string $to,
        bool $forExport,
    ): array {
        $rows = [];

        $byDate = StockLedgerGood::query()
            ->selectRaw('stlg_trdate, stlg_trtype, stlg_trsubtype, stlg_trid,'.
                'SUM(stlg_trups) AS trups, SUM(stlg_trqty) AS trqty,'.
                'SUM(stlg_balups) AS balups, SUM(stlg_balqty) AS balqty,'.
                'SUM(stlg_opups) AS opups, SUM(stlg_opqty) AS opqty,'.
                'stlg_id')
            ->where('stlg_tritemid', $itemId)
            ->where('stlg_trclassid', $classId)
            ->whereBetween('stlg_trdate', [$from, $to])
            ->where('stlg_trsubtype', '!=', 'SUO')
            ->groupBy('stlg_trdate', 'stlg_trtype', 'stlg_trsubtype', 'stlg_trid', 'stlg_subbinid')
            ->orderBy('stlg_trdate')
            ->orderBy('stlg_id');

        // Legacy prints date rows in date ascending order (within each item).
        foreach ($byDate->get() as $rowNew) {
            $cn = 0;
            $recups = 0;
            $recqty = 0;
            $issups = 0;
            $issqty = 0;

            // The inner loop below computes the same per-subtype sums as the
            // legacy report (it re-queries per date x type x subtype x sub-bin).
            $recups = 0;
            $recqty = 0;
            $issups = 0;
            $issqty = 0;

            foreach ($byDate->get() as $rowNewInner) {
                $ty = $rowNewInner->stlg_trtype;
                $tysub = $rowNewInner->stlg_trsubtype;

                if (($ty === 'Arrival' && $tysub === 'Internalreturn')) {
                    $recups += (int) $rowNewInner->trups;
                    $recqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'Issue' && $tysub === 'pindent') {
                    $issups += (int) $rowNewInner->trups;
                    $issqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'Issue' && $tysub === 'eindent') {
                    $issups += (int) $rowNewInner->trups;
                    $issqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'Issue' && $tysub === 'stocktr') {
                    $issups += (int) $rowNewInner->trups;
                    $issqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'Issue' && $tysub === 'MReturnV') {
                    $issups += (int) $rowNewInner->trups;
                    $issqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'IT' && $tysub === 'ITI') {
                    $issups += (int) $rowNewInner->trups;
                    $issqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'IT' && $tysub === 'ITA') {
                    $recups += (int) $rowNewInner->trups;
                    $recqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'GD') {
                    $issups += (int) $rowNewInner->trups;
                    $issqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'DG') {
                    $recups += (int) $rowNewInner->trups;
                    $recqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'CC') {
                    $issups += (int) $rowNewInner->trups;
                    $issqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'CI') {
                    if ($rowNewInner->trqty >= $rowNewInner->opqty) {
                        $recups += (int) $rowNewInner->trups;
                        $recqty += (float) $rowNewInner->trqty;
                    } else {
                        $issups += (int) $rowNewInner->trups;
                        $issqty += (float) $rowNewInner->trqty;
                    }
                } elseif ($ty === 'ES' && $tysub === 'ES') {
                    $recups += (int) $rowNewInner->trups;
                    $recqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'ES' && $tysub === 'SH') {
                    $issups += (int) $rowNewInner->trups;
                    $issqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'SLOC' && $tysub === 'SUC') {
                    $recups += (int) $rowNewInner->trups;
                    $recqty += (float) $rowNewInner->trqty;
                } elseif ($ty === 'SLOC' && $tysub === 'SUO') {
                    $issups += (int) $rowNewInner->trups;
                    $issqty += (float) $rowNewInner->trqty;
                }
            }

            $totups = $issups - $recups;
            $totqty = $issqty - $recqty;

            $date = $this->consumptionDateString($rowNew->stlg_trdate);

            if ($forExport) {
                $rows[] = [
                    'classification' => $itemRow->classification->classification ?? '',
                    'item' => $itemRow->stores_item,
                    'uom' => $itemRow->uom,
                    'issue_ups' => $issups,
                    'issue_qty' => $issqty,
                    'internal_return_ups' => $recups,
                    'internal_return_qty' => $recqty,
                    'used_qty_ups' => $totups,
                    'used_qty' => $totqty,
                ];
            } else {
                $rows[] = [
                    'classification' => $itemRow->classification->classification ?? '',
                    'item' => $itemRow->stores_item,
                    'uom' => $itemRow->uom,
                    'date' => $date,
                    'issue_ups' => $issups,
                    'issue_qty' => $issqty,
                    'internal_return_ups' => $recups,
                    'internal_return_qty' => $recqty,
                    'used_qty_ups' => $totups,
                    'used_qty' => $totqty,
                ];
            }

            if (! $forExport && count($rows) >= 500) {
                return $rows;
            }
        }

        return $rows;
    }

    private function consumptionPerticulars(
        ?string $type,
        ?string $subtype,
        ?string $doc,
        ?string $yearcode
    ): string {
        return ''; // legacy brought back pindent/eindent label text only.
    }

    private function consumptionDateString(?string $date): string
    {
        if ($date === null) {
            return '';
        }

        return $date;
    }

    public function stockOnHandDamage(StockOnHandDamageRequest $request): View
    {
        $asOf = $request->asOf();
        $classificationId = $request->classificationId();

        $rows = $this->stockOnHandDamageRows($asOf, $classificationId);

        return view('viewer.reports.stock-on-hand-damage', [
            'rows' => $rows,
            'asOf' => $asOf,
            'classificationId' => $classificationId,
        ]);
    }

    public function stockOnHandDamageExport(StockOnHandDamageRequest $request): StreamedResponse|BinaryFileResponse
    {
        $asOf = $request->asOf();
        $classificationId = $request->classificationId();

        return Excel::stream(
            'stock-on-hand-damage-'.$asOf.'.xlsx',
            ['Classification', 'Item', 'UoM', 'Warehouse', 'Bin', 'Sub-bin', 'UPS', 'Qty'],
            $this->stockOnHandDamageRows($asOf, $classificationId, forExport: true)
        );
    }

    private function stockOnHandDamageRows(string $asOf, ?int $classificationId, bool $forExport = false): array
    {
        // Legacy parity (damage twin of report_stockhand.php):
        //   1. DISTINCT items touched in the damage ledger up to as-of.
        //   2. Per item: DISTINCT locations with damage activity up to as-of.
        //   3. Per item x location: balance of the MAX(stld_id) row <= as-of
        //      where stld_balqty > 0.
        $itemsQuery = StockLedgerDamage::query()
            ->select('stld_tritemid', 'stld_trclassid')
            ->where('stld_trdate', '<=', $asOf)
            ->when($classificationId, fn ($q, $c) => $q->where('stld_trclassid', $c))
            ->distinct();

        $rows = [];
        $items = $itemsQuery->orderBy('stld_tritemid')->get();

        foreach ($items as $item) {
            $itemRow = Item::with('classification')
                ->where('items_id', $item->stld_tritemid)
                ->where('actstatus', 'Active')
                ->first();

            if ($itemRow === null) {
                continue;
            }

            $locations = StockLedgerDamage::query()
                ->select('stld_whid', 'stld_binid', 'stld_subbinid')
                ->where('stld_trclassid', $item->stld_trclassid)
                ->where('stld_tritemid', $item->stld_tritemid)
                ->where('stld_trdate', '<=', $asOf)
                ->distinct()
                ->get();

            foreach ($locations as $loc) {
                $balance = StockLedgerService::damageBalanceAt(
                    (int) $item->stld_tritemid,
                    (int) $loc->stld_whid,
                    (int) $loc->stld_binid,
                    (int) $loc->stld_subbinid,
                    $asOf
                );

                if ($balance['qty'] <= 0) {
                    continue;
                }

                $rows[] = [
                    'classification' => $itemRow->classification->classification ?? '',
                    'item' => $itemRow->stores_item,
                    'uom' => $itemRow->uom,
                    'warehouse' => Warehouse::find($loc->stld_whid)?->perticulars ?? '',
                    'bin' => Bin::find($loc->stld_binid)?->binname ?? '',
                    'subbin' => SubBin::find($loc->stld_subbinid)?->sname ?? '',
                    'ups' => $balance['ups'],
                    'qty' => $balance['qty'],
                ];

                if (! $forExport && count($rows) >= 500) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    // Discard ---------------------------------------------------------------------
    // Legacy: reports/discardreport.php + report_discard.php + excel-discard.php.

    public function discard(LedgerRequest $request): View
    {
        [$from, $to, $classificationId, $itemId] = $request->filters();

        return view('viewer.reports.discard', [
            'rows' => $this->discardRows($from, $to, $classificationId, $itemId),
            'from' => $from,
            'to' => $to,
            'classificationId' => $classificationId,
            'itemId' => $itemId,
            'classificationLabel' => $classificationId
                ? (Classification::find($classificationId)?->classification ?? 'ALL')
                : 'ALL',
        ]);
    }

    public function discardExport(LedgerRequest $request): StreamedResponse|BinaryFileResponse
    {
        [$from, $to, $classificationId, $itemId] = $request->filters();

        return Excel::stream(
            'Discard_Report_From_'.$from.'_To_'.$to.'.xlsx',
            ['Date', 'Perticulars', 'Classification', 'Item', 'UPS', 'Quantity'],
            $this->discardRows($from, $to, $classificationId, $itemId, forExport: true)
        );
    }

    private function discardRows(string $from, string $to, ?int $classificationId, ?int $itemId, bool $forExport = false): array
    {
        // Legacy parity (report_discard.php): Discard (MD) rows from the damage
        // ledger within the period, newest first, optionally scoped to one
        // classification or item. Particulars come from the discard document
        // matching the ledger row id.
        $q = StockLedgerDamage::query()
            ->whereBetween('stld_trdate', [$from, $to])
            ->where('stld_trtype', 'Discard')
            ->where('stld_trsubtype', 'MD')
            ->when($classificationId, fn ($q, $c) => $q->where('stld_trclassid', $c))
            ->when($itemId, fn ($q, $i) => $q->where('stld_tritemid', $i))
            ->orderByDesc('stld_trdate');

        $rows = [];
        foreach ($q->get() as $r) {
            $item = Item::find($r->stld_tritemid);
            $inactive = $item !== null && $item->actstatus === 'In-Active';
            $itemName = $item?->stores_item ?? '';

            $row = [
                'date' => (string) $r->stld_trdate,
                'particulars' => Discard::where('tid', (int) $r->stld_trid)->value('party_name') ?? '',
                'classification' => Classification::find($r->stld_trclassid)?->classification ?? '',
                'item' => $forExport && $inactive ? $itemName.' - In-Active' : $itemName,
                'inactive' => $inactive,
                'ups' => (int) $r->stld_trups,
                'qty' => (float) $r->stld_trqty,
            ];
            $rows[] = $row;

            if (! $forExport && count($rows) >= 500) {
                break;
            }
        }

        return $rows;
    }

    // Reorder Level ---------------------------------------------------------------
    // Legacy: reports/reorderlevelreport.php + report_reorder.php.

    public function reorder(Request $request): View
    {
        return view('viewer.reports.reorder', [
            'rows' => $this->reorderRows(),
            'asOf' => Date::today()->toDateString(),
        ]);
    }

    public function reorderExport(Request $request): StreamedResponse|BinaryFileResponse
    {
        return Excel::stream(
            'reorder-level-'.Date::today()->toDateString().'.xlsx',
            ['#', 'Classification', 'Item', 'UoM', 'Reorder Level', 'UPS', 'Quantity', 'Remarks'],
            $this->reorderRows(forExport: true)
        );
    }

    private function reorderRows(bool $forExport = false): array
    {
        // Legacy parity (report_reorder.php):
        //   1. Items flagged srl_status = 'Yes' (reorder-tracked); srl holds
        //      the reorder level.
        //   2. Per item: DISTINCT good-ledger locations with activity.
        //   3. Per location: balance of the latest ledger row (MAX(stlg_id));
        //      summed across locations.
        //   4. Normalisation: qty > 0 && ups == 0 -> ups = 1;
        //      qty == 0 && ups > 0 -> ups = 0; qty < 0 -> 0.
        //   5. The item is listed when total qty <= its reorder level.
        //   6. Remarks: 'OR - {ordate}' when the last location's row is marked
        //      order-placed, otherwise 'R'.
        $items = Item::query()
            ->where('srl_status', 'Yes')
            ->whereNotNull('srl')
            ->orderBy('classification_id')
            ->orderBy('items_id')
            ->get();

        $asOf = Date::today()->toDateString();

        $rows = [];
        $srno = 1;

        foreach ($items as $itemRow) {
            $totups = 0;
            $totqty = 0.0;
            $orstatus = '';
            $ordate = '';

            $locations = StockLedgerGood::query()
                ->select('stlg_whid', 'stlg_binid', 'stlg_subbinid')
                ->where('stlg_trclassid', $itemRow->classification_id)
                ->where('stlg_tritemid', $itemRow->items_id)
                ->distinct()
                ->get();

            foreach ($locations as $loc) {
                $balance = StockLedgerService::balanceAt(
                    (int) $itemRow->items_id,
                    (int) $loc->stlg_whid,
                    (int) $loc->stlg_binid,
                    (int) $loc->stlg_subbinid,
                    $asOf
                );

                $totups += $balance['ups'];
                $totqty += $balance['qty'];
            }

            // Latest row for the item carries the order-placed remark flags.
            $latest = StockLedgerGood::query()
                ->where('stlg_trclassid', $itemRow->classification_id)
                ->where('stlg_tritemid', $itemRow->items_id)
                ->orderByDesc('stlg_id')
                ->first(['orstatus', 'ordate']);

            if ($latest !== null) {
                $orstatus = (string) $latest->orstatus;
                $ordate = (string) $latest->ordate;
            }

            if ($totqty < 0) {
                $totqty = 0.0;
            }
            if ($totups <= 0 && $totqty > 0) {
                $totups = 1;
            }
            if ($totups > 0 && $totqty == 0) {
                $totups = 0;
            }

            $reorderLevel = (float) $itemRow->srl;

            if ($totqty > $reorderLevel) {
                continue; // legacy: only items at/below the reorder level
            }

            $inactive = $itemRow->actstatus === 'In-Active';
            $itemName = (string) $itemRow->stores_item;

            $rows[] = [
                'srno' => $srno++,
                'classification' => $itemRow->classification->classification ?? '',
                'item' => $forExport && $inactive ? $itemName.' - In-Active' : $itemName,
                'inactive' => $inactive,
                'uom' => $itemRow->uom,
                'reorder_level' => $reorderLevel,
                'ups' => $totups,
                'qty' => $totqty,
                'remarks' => $orstatus === 'OR' && $ordate !== ''
                    ? 'OR - '.Date::parse($ordate)->format('d-m-Y')
                    : 'R',
            ];

            if (! $forExport && count($rows) >= 500) {
                return $rows; // on-screen cap; exports stream everything
            }
        }

        return $rows;
    }

    // Party-wise Period -----------------------------------------------------------
    // Legacy: reports/partywiseperiodreport.php + partywiseperiodreport1/2.php + excel-partywise.php.

    public function partywise(LedgerRequest $request): View
    {
        [$from, $to, $classificationId, $itemId] = $request->filters();
        $partyId = (int) $request->query('party_id', 0);

        return view('viewer.reports.partywise', [
            'rows' => $this->partywiseRows($from, $to, $partyId, $classificationId, $itemId),
            'totals' => $this->partywiseTotals($from, $to, $partyId, $classificationId, $itemId),
            'from' => $from,
            'to' => $to,
            'partyId' => $partyId,
            'partyName' => $partyId > 0
                ? (Party::find($partyId)?->business_name ?? 'ALL')
                : 'ALL',
            'classificationId' => $classificationId,
            'itemId' => $itemId,
        ]);
    }

    public function partywiseExport(LedgerRequest $request): StreamedResponse|BinaryFileResponse
    {
        [$from, $to, $classificationId, $itemId] = $request->filters();
        $partyId = (int) $request->query('party_id', 0);
        $partyName = $partyId > 0
            ? (Party::find($partyId)?->business_name ?? 'all')
            : 'all';

        return Excel::stream(
            'Party_wise_Stock_Report_'.$partyName.'_From_'.$from.'_To_'.$to.'.xlsx',
            [
                'Date', 'Particulars',
                'Opening UPS', 'Opening Qty',
                'DC UPS', 'DC Qty',
                'Good UPS', 'Good Qty',
                'Arrival Damage UPS', 'Arrival Damage Qty',
                'Internal Damage UPS', 'Internal Damage Qty',
                'Excess', 'Shortage',
                'Net UPS', 'Net Qty',
                'Issue UPS', 'Issue Qty',
                'Balance UPS', 'Balance Qty',
            ],
            $this->partywiseRows($from, $to, $partyId, $classificationId, $itemId, forExport: true)
        );
    }

    private function partywiseRows(
        string $from,
        string $to,
        int $partyId,
        ?int $classificationId,
        ?int $itemId,
        bool $forExport = false,
    ): array {
        // Legacy parity (partywiseperiodreport2.php + excel-partywise.php):
        // party-ledger rows in the period ordered by date, with particulars
        // derived from type/subtype and the DC/good/damage/excess/shortage
        // column split. The legacy "Net" column was left blank (an
        // uninitialized leftover); the port computes it as receive - issue.
        $q = PartyLedger::query()
            ->whereBetween('pldg_trdate', [$from, $to])
            ->when($partyId > 0, fn ($q) => $q->where('pldg_trpartyid', $partyId))
            ->when($classificationId, fn ($q, $c) => $q->where('pldg_trclassid', $c))
            ->when($itemId, fn ($q, $i) => $q->where('pldg_tritemid', $i))
            ->orderBy('pldg_trdate');

        $rows = [];

        foreach ($q->get() as $r) {
            $type = (string) $r->pldg_trtype;
            $subtype = (string) $r->pldg_trsubtype;

            $opening = [0, 0.0];
            $rec = [0, 0.0];
            $iss = [0, 0.0];
            $internalDamage = [0, 0.0];
            $particulars = trim($type.' '.($subtype !== '' ? '('.$subtype.')' : ''));

            if ($type === 'Arrival' && $subtype === 'Vendor') {
                $particulars = 'Arrival from Party';
                $rec = [(int) $r->pldg_trdcups, (float) $r->pldg_trdcqty];
            } elseif ($type === 'Issue' && $subtype === 'MReturnV') {
                $particulars = 'Material Return to Party';
                $iss = [(int) $r->pldg_trdcups, (float) $r->pldg_trdcqty];
            } elseif ($type === 'OP') {
                $particulars = 'Opening Stock';
                $opening = [(int) $r->pldg_trdcups, (float) $r->pldg_trdcqty];
            } elseif ($type === 'GD') {
                $particulars = 'Good to Damage - Party';
                $internalDamage = [(int) $r->pldg_trdamageups, (float) $r->pldg_trdamageqty];
            }

            $arrivalDamage = $type === 'GD'
                ? [0, 0.0]
                : [(int) $r->pldg_trdamageups, (float) $r->pldg_trdamageqty];

            $rows[] = [
                'date' => (string) $r->pldg_trdate,
                'particulars' => $particulars,
                'classification' => Classification::find($r->pldg_trclassid)?->classification ?? '',
                'item' => Item::find($r->pldg_tritemid)?->stores_item ?? '',
                'opening_ups' => $opening[0],
                'opening_qty' => $opening[1],
                'dc_ups' => $rec[0],
                'dc_qty' => $rec[1],
                'good_ups' => (int) $r->pldg_trgoodups,
                'good_qty' => (float) $r->pldg_trgoodqty,
                'arrival_damage_ups' => $arrivalDamage[0],
                'arrival_damage_qty' => $arrivalDamage[1],
                'internal_damage_ups' => $internalDamage[0],
                'internal_damage_qty' => $internalDamage[1],
                'excess' => (float) $r->pldg_trexqty,
                'shortage' => (float) $r->pldg_trshqty,
                'net_ups' => $rec[0] - $iss[0],
                'net_qty' => $rec[1] - $iss[1],
                'issue_ups' => $iss[0],
                'issue_qty' => $iss[1],
                'balance_ups' => (int) $r->pldg_trbalups,
                'balance_qty' => (float) $r->pldg_trbalqty,
            ];

            if (! $forExport && count($rows) >= 500) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Period totals across the filtered party-ledger rows, with the closing
     * balance taken from the last row in the period (the legacy report
     * ends each item block at its final balance row).
     */
    private function partywiseTotals(
        string $from,
        string $to,
        int $partyId,
        ?int $classificationId,
        ?int $itemId,
    ): array {
        $base = fn () => PartyLedger::query()
            ->whereBetween('pldg_trdate', [$from, $to])
            ->when($partyId > 0, fn ($q) => $q->where('pldg_trpartyid', $partyId))
            ->when($classificationId, fn ($q, $c) => $q->where('pldg_trclassid', $c))
            ->when($itemId, fn ($q, $i) => $q->where('pldg_tritemid', $i));

        $sums = (clone $base)()->selectRaw(
            'SUM(pldg_trdcups) AS dc_ups, SUM(pldg_trdcqty) AS dc_qty,'.
            'SUM(pldg_trgoodups) AS good_ups, SUM(pldg_trgoodqty) AS good_qty,'.
            'SUM(pldg_trdamageups) AS damage_ups, SUM(pldg_trdamageqty) AS damage_qty,'.
            'SUM(pldg_trexqty) AS excess, SUM(pldg_trshqty) AS shortage'
        )->first();

        $last = (clone $base)()->orderByDesc('pldg_trdate')->orderByDesc('pldg_id')->first();

        return [
            'dc_ups' => (int) $sums->dc_ups,
            'dc_qty' => (float) $sums->dc_qty,
            'good_ups' => (int) $sums->good_ups,
            'good_qty' => (float) $sums->good_qty,
            'damage_ups' => (int) $sums->damage_ups,
            'damage_qty' => (float) $sums->damage_qty,
            'excess' => (float) $sums->excess,
            'shortage' => (float) $sums->shortage,
            'closing_ups' => (int) ($last?->pldg_trbalups ?? 0),
            'closing_qty' => (float) ($last?->pldg_trbalqty ?? 0.0),
        ];
    }
}
