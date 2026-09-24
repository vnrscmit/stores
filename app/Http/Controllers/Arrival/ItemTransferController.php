<?php

namespace App\Http\Controllers\Arrival;

use App\Http\Controllers\Controller;
use App\Models\Classification;
use App\Models\Item;
use App\Models\ItemTransfer;
use App\Models\ItemTransferItem;
use App\Models\StockLedgerGood;
use App\Support\ArrivalNumbering;
use App\Support\ArrivalStatus;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\FiscalYear;
use App\Support\StockLedgerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The inter-item transfer (ITI/ITA) module — legacy Transaction/
 * add_interitem.php + getuser_iit*.php + add_iitr_preview.php. One document
 * converts stock of a source item into one or more destination items,
 * location by location:
 *
 *  1. Workspace: pick the source item and a source good-ledger row (a
 *     sub-bin holding it); each transfer line is anchored to that row id
 *     (item_transfer_items.rowid — legacy tbl_iitr_sub.rowid) and carries
 *     the destination item + location + ups_to/qty_to. The header
 *     (item_transfers row, iitrflg=0) is created with the first line
 *     (legacy getuser_iitupdate.php trid=0 branch).
 *  2. Line edit = delete-and-reinsert (legacy getuser_iitetdupdate.php
 *     deletes the source row group and re-adds all submitted rows).
 *  3. Final post (add_iitr_preview.php), per rowid group:
 *     - ITI (direction out) at the SOURCE location: tr = Σ(ups_to/qty_to)
 *       of the group, bal = op − tr, trtype 'IT', subtype 'ITI';
 *     - ITA (direction in) per sub row at the DESTINATION: bal = op + tr —
 *       LEGACY QUIRK preserved verbatim: bal = tr when the destination
 *       opening is 0/0 (the legacy `if ($opups > 0) bal = op + ups` resets
 *       instead of adds; balance floats when only one side opens at 0),
 *       trtype 'IT', subtype 'ITA';
 *     - reorder pass on both sides (applyReorderFlag);
 *     - iitr_code from the `iitr` counter (TIIT...) — documented deviation:
 *       legacy's numbering block was commented out, so committed documents
 *       were never addressed; the port assigns the serial at commit;
 *     - iitrflg=1 + status='posted' (legacy never set the flag; the port
 *       mirrors the arrivals-family posted marker).
 *     The whole post is one DB transaction — legacy was not.
 *
 * Transfer quantity is guarded: each line may not exceed the source
 * location's current balance (the legacy UI checked it client-side in
 * pform(); the port enforces it server-side at save and post).
 */
class ItemTransferController extends Controller
{
    use AuthorizesRequests;

    /** The queue: open workspaces + posted conversions. */
    public function index(): View
    {
        $this->authorize('post-transactions');

        $transfers = ItemTransfer::query()
            ->where('yearcode', FiscalYear::yearcode())
            ->when(request('stage') === ArrivalStatus::OPEN, fn ($q) => $q->where('iitrflg', 0))
            ->when(request('stage') === ArrivalStatus::POSTED, fn ($q) => $q->where('iitrflg', 1))
            ->orderByDesc('iitr_id')
            ->paginate(20)
            ->withQueryString();

        return view('itransfers.queue', ['transfers' => $transfers]);
    }

    /** Entry screen (legacy add_interitem.php). */
    public function create(): View
    {
        $this->authorize('post-transactions');

        return view('itransfers.create', [
            'classifications' => Classification::query()
                ->orderBy('classification')
                ->get(['classification_id', 'classification']),
        ]);
    }

    /**
     * Save one transfer line (AJAX, legacy getuser_iitupdate.php): with no
     * transfer id the header is created first (trid=0 branch), then the row
     * is inserted against the selected source good-ledger row.
     */
    public function storeLine(Request $request): JsonResponse
    {
        $this->authorize('post-transactions');

        $data = $this->validateLine($request);

        $result = DB::transaction(function () use ($data) {
            $transfer = isset($data['transfer_id'])
                ? $this->findOpenTransfer((int) $data['transfer_id'])
                : null;

            $transfer ??= $this->createHeader($data);

            $saved = $this->saveLine($transfer, $data);

            Audit::log('itransfer.conversion', 'line.create', $transfer, null, [
                'source_stlg_id' => $data['rowid'],
                'destination_item' => $data['items_id'],
                'rows' => count($saved),
            ]);

            return [$transfer, $saved];
        });

        [$transfer, $saved] = $result;

        return response()->json([
            'ok' => true,
            'transfer_id' => $transfer->iitr_id,
            'redirect' => route('itransfers.workspace', $transfer),
            'line' => ['rows' => $saved],
        ]);
    }

    /**
     * Replace one source row group (legacy getuser_iitetdupdate.php:
     * delete-then-reinsert of the whole `rowid` group — the editable
     * unit is the group, matching the workspace's edit control).
     */
    public function updateLine(Request $request, int $rowid): JsonResponse
    {
        $this->authorize('post-transactions');

        $data = $this->validateLine($request);
        abort_unless((int) $data['rowid'] === $rowid, 422, 'Row mismatch with the URL.');

        [$transfer, $group] = DB::transaction(function () use ($data, $rowid) {
            $transfer = $this->findOpenTransfer((int) $data['transfer_id']);
            abort_unless(
                ItemTransferItem::query()->where('iitr_id', $transfer->iitr_id)->where('rowid', $rowid)->exists(),
                404,
                'Nothing to edit for this source row.'
            );

            ItemTransferItem::query()
                ->where('iitr_id', $transfer->iitr_id)
                ->where('rowid', $rowid)
                ->delete();

            $group = $this->saveLine($transfer, $data);

            Audit::log('itransfer.conversion', 'line.update', $transfer, null, [
                'source_stlg_id' => $rowid,
                'rows' => count($data['targets']),
            ]);

            return [$transfer, $group];
        });

        return response()->json([
            'ok' => true,
            'transfer_id' => $transfer->iitr_id,
            'lines' => $group,
        ]);
    }

    /**
     * Remove one source row group (legacy: getuser_iitetdupdate.php with
     * all quantity fields empty deletes the group's tbl_iitr_sub rows).
     */
    public function deleteLine(Request $request, int $rowid): JsonResponse
    {
        $this->authorize('post-transactions');

        $transferId = (int) $request->input('transfer_id');

        $deleted = DB::transaction(function () use ($transferId, $rowid) {
            $transfer = $this->findOpenTransfer($transferId);

            $deleted = ItemTransferItem::query()
                ->where('iitr_id', $transfer->iitr_id)
                ->where('rowid', $rowid)
                ->delete();

            Audit::log('itransfer.conversion', 'line.delete', $transfer, [
                'source_stlg_id' => $rowid,
            ]);

            return $deleted;
        });

        abort_unless($deleted > 0, 404, 'Nothing to delete for this source row.');

        return response()->json(['ok' => true]);
    }

    /**
     * The workspace: header state, source rows with their destination
     * lines, and the add-line form (legacy add_interitem.php workspace
     * panes: Stock in Hand / Transferred to / Balance).
     */
    public function workspace(ItemTransfer $transfer): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($transfer);

        return view('itransfers.workspace', [
            'transfer' => $transfer,
            'groups' => $this->groupsOf($transfer),
            'classifications' => Classification::query()
                ->orderBy('classification')
                ->get(['classification_id', 'classification']),
        ]);
    }

    /** Edit the header fields while open (legacy preview-screen update). */
    public function updateHeader(Request $request, ItemTransfer $transfer): RedirectResponse
    {
        $this->authorize('post-transactions');
        $this->assertOpen($transfer);
        $this->assertSameYear($transfer);

        $data = $request->validate([
            'tdate' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $transfer->fill([
            'tdate' => $data['tdate'],
            'remarks' => $data['remarks'] ?? $transfer->remarks,
        ]);
        $transfer->save();

        Audit::log('itransfer.conversion', 'header.update', $transfer);

        return back()->with('success', 'Header updated.');
    }

    /**
     * Final post (legacy add_iitr_preview.php frm_action=submit), per
     * source-row group: one ITI out at the source, one ITA in per
     * destination row (with the verbatim op==0 balance-reset quirk),
     * reorder passes, then the committed serial + flags. Transactional and
     * idempotent — legacy was neither.
     */
    public function post(ItemTransfer $transfer): RedirectResponse
    {
        $this->authorize('post-transactions');

        if ((int) $transfer->iitrflg === 1) {
            return redirect()->route('itransfers.show', $transfer)
                ->with('error', 'This inter-item transfer has already been posted.');
        }

        $this->assertSameYear($transfer);

        $yearcode = FiscalYear::yearcode();

        $posted = DB::transaction(function () use ($transfer, $yearcode) {
            $groups = ItemTransferItem::query()
                ->where('iitr_id', $transfer->iitr_id)
                ->orderBy('rowid')
                ->get()
                ->groupBy('rowid');
            abort_unless($groups->isNotEmpty(), 422, 'This transfer has no destination lines.');

            $trdate = (string) $transfer->tdate;

            foreach ($groups as $rowid => $rows) {
                $source = StockLedgerGood::query()->where('stlg_id', $rowid)->first();
                abort_if($source === null, 422, "Source stock row {$rowid} no longer exists.");

                $upsTo = (int) $rows->sum('ups_to');
                $qtyTo = (float) $rows->sum('qty_to');

                // ITI — the source side (direction out, trtype 'IT',
                // subtype 'ITI', trid = the iitr document id).
                StockLedgerService::post([
                    'direction' => 'out',
                    'yearcode' => $yearcode,
                    'trtype' => 'IT',
                    'trsubtype' => 'ITI',
                    'trid' => $transfer->iitr_id,
                    'trdate' => $trdate,
                    'classid' => $source->stlg_trclassid,
                    'item_id' => (int) $source->stlg_tritemid,
                    'whid' => (int) $source->stlg_whid,
                    'binid' => (int) $source->stlg_binid,
                    'subbinid' => (int) $source->stlg_subbinid,
                    'ups' => $upsTo,
                    'qty' => $qtyTo,
                ]);

                StockLedgerService::applyReorderFlag((int) $source->stlg_tritemid);

                foreach ($rows as $row) {
                    // ITA — the destination side (direction in, subtype
                    // 'ITA'); the balance-reset quirk is verbatim legacy
                    // math, see postIta().
                    $this->postIta($transfer, $yearcode, $trdate, $row);

                    StockLedgerService::applyReorderFlag((int) $row->items_id);
                }
            }

            $transfer->iitr_code = ArrivalNumbering::primeItemTransferCommitted($yearcode);
            $transfer->iitrflg = 1;
            $transfer->status = ArrivalStatus::POSTED;
            $transfer->save();

            Audit::log('itransfer.conversion', 'post', $transfer, null, [
                'iitr_code' => $transfer->iitr_code,
                'groups' => $groups->count(),
                'lines' => $groups->flatten()->count(),
            ]);

            return $transfer;
        });

        return redirect()
            ->route('itransfers.show', $posted)
            ->with('success', 'Inter-item transfer posted: '.DocumentNumber::pretty('iitr', (int) $posted->iitr_code, $yearcode).'. Stock updated.');
    }

    /** Detail screen (legacy add_iitr_preview.php render). */
    public function show(ItemTransfer $transfer): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($transfer);

        return view('itransfers.show', [
            'transfer' => $transfer,
            'groups' => $this->groupsOf($transfer),
        ]);
    }

    // ------------------------------------------------------------------

    /** Read-only source availability for the entry screen. */
    public function sourceAvailability(int $classification, int $item): JsonResponse
    {
        $this->authorize('post-transactions');

        return response()->json(['ok' => true, 'availability' => $this->sourceRows($item)]);
    }

    /** Destination-item dropdown for a classification (excludes the source). */
    public function destinationItems(int $classification, int $item): JsonResponse
    {
        $this->authorize('post-transactions');

        $items = Item::query()
            ->where('classification_id', $classification)
            ->where('items_id', '!=', $item)
            ->where('actstatus', 'Active')
            ->orderBy('stores_item')
            ->get(['items_id', 'stores_item', 'uom']);

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** Destination SLOC dropdown for an item: real locations only (the
     * migrated sub_bins carry placeholder rows with NULL whid/binid —
     * those are never valid transfer targets). */
    public function destinationLocations(int $item): JsonResponse
    {
        $this->authorize('post-transactions');

        $locations = DB::table('sub_bins')
            ->where('sid', '!=', 0)
            ->whereNotNull('whid')
            ->whereNotNull('binid')
            ->orderBy('whid')
            ->orderBy('binid')
            ->orderBy('sid')
            ->get(['whid', 'binid', 'sid']);

        return response()->json(['ok' => true, 'locations' => $locations]);
    }

    // ------------------------------------------------------------------

    /**
     * Positive latest good-ledger balances for an item — the selectable
     * source rows (legacy getuser_iit_slocshow.php: MAX(stlg_id) per
     * location with stlg_balqty > 0).
     *
     * @return array<int, array<string, mixed>>
     */
    private function sourceRows(int $itemId): array
    {
        return StockLedgerGood::query()
            ->select('stlg_whid', 'stlg_binid', 'stlg_subbinid')
            ->where('stlg_tritemid', $itemId)
            ->groupBy('stlg_whid', 'stlg_binid', 'stlg_subbinid')
            ->get()
            ->map(function ($loc) use ($itemId) {
                $latest = StockLedgerGood::query()
                    ->where('stlg_tritemid', $itemId)
                    ->where('stlg_whid', $loc->stlg_whid)
                    ->where('stlg_binid', $loc->stlg_binid)
                    ->where('stlg_subbinid', $loc->stlg_subbinid)
                    ->orderByDesc('stlg_id')
                    ->first();

                if ($latest === null || (float) $latest->stlg_balqty <= 0) {
                    return null;
                }

                return [
                    'stlg_id' => (int) $latest->stlg_id,
                    'whid' => (int) $latest->stlg_whid,
                    'binid' => (int) $latest->stlg_binid,
                    'subbinid' => (int) $latest->stlg_subbinid,
                    'ups' => (int) $latest->stlg_balups,
                    'qty' => (float) $latest->stlg_balqty,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The workspace's grouped view: per source row, the destination lines
     * with their names and remaining source balance.
     *
     * @return array<int, array<string, mixed>>
     */
    private function groupsOf(ItemTransfer $transfer): array
    {
        $lines = ItemTransferItem::query()
            ->where('iitr_id', $transfer->iitr_id)
            ->orderBy('rowid')
            ->orderBy('iitrsub_id')
            ->get();

        return $lines
            ->groupBy('rowid')
            ->map(function ($rows, $rowid) {
                $source = StockLedgerGood::query()->where('stlg_id', $rowid)->first();

                $movedQty = (float) $rows->sum('qty_to');

                return [
                    'rowid' => (int) $rowid,
                    'source' => $source === null ? null : [
                        'item_id' => (int) $source->stlg_tritemid,
                        'item_name' => optional(Item::query()->find($source->stlg_tritemid))->stores_item ?? '—',
                        'whid' => (int) $source->stlg_whid,
                        'binid' => (int) $source->stlg_binid,
                        'subbinid' => (int) $source->stlg_subbinid,
                        'ups' => (int) $source->stlg_balups,
                        'qty' => (float) $source->stlg_balqty,
                    ],
                    'remaining_qty' => $source === null ? 0.0 : (float) $source->stlg_balqty - $movedQty,
                    'lines' => $rows->map(fn (ItemTransferItem $r) => [
                        'iitrsub_id' => (int) $r->iitrsub_id,
                        'classification_id' => (int) $r->classification_id,
                        'items_id' => (int) $r->items_id,
                        'item_name' => optional(Item::query()->find($r->items_id))->stores_item ?? '—',
                        'uom' => (string) $r->uom,
                        'whid' => (int) $r->whid,
                        'binid' => (int) $r->binid,
                        'subbinid' => (int) $r->subbinid,
                        'ups_to' => (int) $r->ups_to,
                        'qty_to' => (float) $r->qty_to,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /** Validation for a line save: header + source row + destination rows. */
    private function validateLine(Request $request): array
    {
        $data = $request->validate([
            'transfer_id' => ['nullable', 'integer'],
            'classification_id' => ['required', 'integer'],
            'items_id' => ['required', 'integer'],
            'rowid' => ['required', 'integer'],
            'ups_from' => ['required', 'integer', 'min:0', 'max:999999'],
            'qty_from' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'tdate' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.classification_id' => ['required', 'integer'],
            'targets.*.items_id' => ['required', 'integer'],
            'targets.*.whid' => ['required', 'integer'],
            'targets.*.binid' => ['required', 'integer'],
            'targets.*.subbin' => ['required', 'integer'],
            'targets.*.ups_to' => ['required', 'integer', 'min:1', 'max:999999'],
            'targets.*.qty_to' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
        ]);

        // The source item must exist, be active and match the classification
        // (legacy trusted the client chain; the port verifies server-side).
        $sourceItem = Item::query()->find((int) $data['items_id']);
        abort_if($sourceItem === null, 422, 'Source item not found.');
        abort_unless((int) $sourceItem->classification_id === (int) $data['classification_id'], 422,
            'The source item does not belong to the selected classification.');
        abort_unless($sourceItem->actstatus === 'Active', 422, 'The source item is inactive.');

        $data['uom_from'] = (string) $sourceItem->uom;

        $source = StockLedgerGood::query()->where('stlg_id', $data['rowid'])->first();
        abort_if($source === null, 422, 'The selected source stock row no longer exists.');
        abort_unless((int) $source->stlg_tritemid === (int) $data['items_id'], 422,
            'The selected source row belongs to a different item.');

        // Each destination must be a real, active item of the same
        // classification, excluding the source item itself (legacy
        // getuser_iit_slocshow.php: items_id != source), with a real
        // wh -> bin -> sub-bin chain.
        $sumQty = 0.0;
        $sumUps = 0;

        foreach ($data['targets'] as $i => $target) {
            $dest = Item::query()->find((int) $target['items_id']);
            abort_if($dest === null, 422, "Destination row {$i}: item not found.");
            abort_unless((int) $dest->classification_id === (int) $data['classification_id'], 422,
                "Destination row {$i}: the item does not belong to the selected classification.");
            abort_unless($dest->actstatus === 'Active', 422, "Destination row {$i}: the item is inactive.");
            abort_unless((int) $target['items_id'] !== (int) $data['items_id'], 422,
                "Destination row {$i}: the destination must differ from the source item.");

            $whid = (int) $target['whid'];
            $binid = (int) $target['binid'];
            $subbinid = (int) $target['subbin'];

            abort_unless(DB::table('warehouses')->where('whid', $whid)->exists(), 422, "Destination row {$i}: warehouse not found.");
            $bin = DB::table('bins')->where('binid', $binid)->where('whid', $whid)->first();
            abort_if($bin === null, 422, "Destination row {$i}: the bin does not belong to the warehouse.");
            $subbin = DB::table('sub_bins')->where('sid', $subbinid)->where('binid', $binid)->where('whid', $whid)->first();
            abort_if($subbin === null, 422, "Destination row {$i}: the sub-bin does not belong to the bin.");

            $sumQty += (float) $target['qty_to'];
            $sumUps += (int) $target['ups_to'];
        }

        // Transfer quantity may not exceed the source location's current
        // balance (legacy client-side pform() check; enforced server-side).
        abort_unless($sumQty <= (float) $source->stlg_balqty + 0.001, 422,
            'The distributed quantity ('.$sumQty.') exceeds the source balance ('.$source->stlg_balqty.').');

        return $data;
    }

    /** Create the header on first line post (legacy trid=0 branch). */
    private function createHeader(array $data): ItemTransfer
    {
        $transfer = ItemTransfer::query()->create([
            'tdate' => $data['tdate'],
            'classification_id' => (int) $data['classification_id'],
            'items_id_from' => (int) $data['items_id'],
            'uom_from' => (string) $data['uom_from'],
            'typ' => 'good',
            'yearcode' => FiscalYear::yearcode(),
            'remarks' => $data['remarks'] ?? null,
            'iitrflg' => 0,
            'status' => ArrivalStatus::OPEN,
        ]);

        Audit::log('itransfer.conversion', 'open', $transfer);

        return $transfer;
    }

    /**
     * Persist the submitted destination rows for one source row. The row
     * carrying ups_from/qty_from snapshot columns: legacy stored the source
     * location's balance at line time ($oups/$oqty) there.
     *
     * @return array<int, array<string, int|float>>
     */
    private function saveLine(ItemTransfer $transfer, array $data): array
    {
        $saved = [];

        foreach ($data['targets'] as $target) {
            $row = ItemTransferItem::query()->create([
                'iitr_id' => $transfer->iitr_id,
                'classification_id' => (int) $target['classification_id'],
                'items_id' => (int) $target['items_id'],
                'uom' => (string) Item::query()->where('items_id', $target['items_id'])->value('uom'),
                'whid' => (int) $target['whid'],
                'binid' => (int) $target['binid'],
                'subbinid' => (int) $target['subbin'],
                'ups_from' => (int) $data['ups_from'],
                'qty_from' => (float) $data['qty_from'],
                'ups_to' => (int) $target['ups_to'],
                'qty_to' => (float) $target['qty_to'],
                'rowid' => (int) $data['rowid'],
            ]);

            $saved[] = ['id' => $row->iitrsub_id, 'items_id' => (int) $row->items_id];
        }

        return $saved;
    }

    /**
     * The ITA destination row — verbatim add_iitr_preview.php math:
     *
     *   opups > 0 ? balups = opups + ups : balups = ups
     *   opqty > 0 ? balqty = opqty + qty : balqty = qty
     *
     * i.e. a 0/0 opening RESETS the balance to the transfer quantity
     * instead of adding to it (when one side opens at zero the balances
     * float independently). Preserved deliberately — see the class
     * docblock and docs/PHASE9.md §2.4.
     */
    private function postIta(ItemTransfer $transfer, string $yearcode, string $trdate, ItemTransferItem $row): void
    {
        $latest = StockLedgerService::latestRow(
            (int) $row->items_id,
            (int) $row->whid,
            (int) $row->binid,
            (int) $row->subbinid
        );

        $opups = (int) ($latest->stlg_balups ?? 0);
        $opqty = (float) ($latest->stlg_balqty ?? 0);

        $balups = $opups > 0 ? $opups + (int) $row->ups_to : (int) $row->ups_to;
        $balqty = $opqty > 0 ? $opqty + (float) $row->qty_to : (float) $row->qty_to;

        $ledger = new StockLedgerGood;
        $ledger->yearcode = $yearcode;
        $ledger->stlg_trtype = 'IT';
        $ledger->stlg_trsubtype = 'ITA';
        $ledger->stlg_trid = $transfer->iitr_id;
        $ledger->stlg_trpartyid = '0';
        $ledger->stlg_trdate = $trdate;
        $ledger->stlg_trclassid = (int) $row->classification_id;
        $ledger->stlg_tritemid = (int) $row->items_id;
        $ledger->stlg_whid = (int) $row->whid;
        $ledger->stlg_binid = (int) $row->binid;
        $ledger->stlg_subbinid = (int) $row->subbinid;
        $ledger->stlg_opups = max(0, $opups);
        $ledger->stlg_opqty = $opqty;
        $ledger->stlg_trups = (int) $row->ups_to;
        $ledger->stlg_trqty = (float) $row->qty_to;
        $ledger->stlg_balups = max(0, $balups);
        $ledger->stlg_balqty = $balqty;
        $ledger->save();

        // Direction 'in': the sub-bin flips to Good (legacy ITA did not
        // touch tbl_subbin, but the ITI out side leaves the source at its
        // legacy state; see the parity note in docs/PHASE9.md §5).
        DB::table('sub_bins')->where('sid', (int) $row->subbinid)->update(['status' => 'Good']);
    }

    /** Find this year's open workspace by id; posted documents are immutable. */
    private function findOpenTransfer(int $transferId): ItemTransfer
    {
        $transfer = ItemTransfer::query()
            ->where('iitr_id', $transferId)
            ->where('yearcode', FiscalYear::yearcode())
            ->first();

        abort_if($transfer === null, 404, 'Transfer workspace not found.');
        $this->assertOpen($transfer);

        return $transfer;
    }

    private function assertOpen(ItemTransfer $transfer): void
    {
        abort_if((int) $transfer->iitrflg === 1, 422, 'This transfer has already been posted and is immutable.');
    }

    private function assertSameYear(ItemTransfer $transfer): void
    {
        abort_unless(strcasecmp((string) $transfer->yearcode, FiscalYear::yearcode()) === 0, 404,
            'Transfer workspace not found.');
    }
}
