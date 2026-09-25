<?php

namespace App\Http\Controllers;

use App\Models\Classification;
use App\Models\Discard;
use App\Models\DiscardItem;
use App\Models\DiscardSloc;
use App\Models\GatePass;
use App\Models\Item;
use App\Models\StockLedgerDamage;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\FiscalYear;
use App\Support\StockLedgerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The Material Discard adjustment — legacy Transaction/
 * add_material_discard.php + getuser_discard3.php (line save) +
 * add_discard_str_preview.php (final submit).
 *
 * One document discards damaged stock OUT of the DAMAGE ledger (not the
 * good ledger) at one or more SLOCs, item by item:
 *
 *  1. Workspace: per item line, the available damage-ledger rows for the
 *     item are listed (legacy getuser_discard_slocshow.php: MAX(stld_id)
 *     per location, stld_balqty > 0) and each carries its own discard
 *     ups/qty. The header (discards row, ddflg=0) is created with the
 *     first line (legacy getuser_discard3.php trid=0 branch; tcode=trid).
 *  2. discard_items rows carry the per-item totals (legacy: updated to
 *     SUM(ups)/SUM(qty) after the sloc rows are inserted);
 *     discard_slocs rows are per damage-ledger row with
 *     discard_rowid = stld_id, discard_type 'MD'.
 *  3. Final post (add_discard_str_preview.php frm_action=submit), per
 *     discard_slocs row: one DAMAGE-ledger row (direction out, trtype
 *     'Discard', subtype 'MD', trid = the discard document id, partyid =
 *     party_name) with opening = the referenced row's balance, bal =
 *     op − tr and the verbatim UPS normalization:
 *       if (balqty > 0 && balups == 0) balups = 1;
 *       if (balqty == 0 && balups > 0) balups = 0;
 *     (StockLedgerService::post already encodes both rules; the second one
 *     runs there unconditionally on balqty==0 — equivalent for every
 *     reachable state here.) The service's Empty flip is UNCONDITIONAL on
 *     balqty==0, but legacy's check (add_discard_str_preview.php) is
 *     cross-item: the sub-bin empties only when no location hosting OTHER
 *     items of the classification holds a positive latest balance — so the
 *     port re-scopes the flip per the verbatim check (see
 *     restoreLegacyEmptyScope). No reorder pass: the legacy discard
 *     preview never calls one (unlike the issue/ITI/ITA writers).
 *  4. Commit: dd_code/ncode from the `discard` counter (legacy MAX+1
 *     per yearcode), ddflg=1, and a gate_passes row (gpcode MAX+1,
 *     trid "MD{dd_code}") for the gate movement.
 *  The whole post is one DB transaction — legacy was not.
 *
 * Discard quantity is guarded server-side: each SLOC row may not exceed
 * that damage row's balance (the legacy UI checked it in upschk/qtychk).
 */
class DiscardController extends Controller
{
    use AuthorizesRequests;

    /** The queue (legacy add_discard.php): committed discards, paginated. */
    public function index(Request $request): View
    {
        $this->authorize('post-transactions');

        $discards = Discard::query()
            ->where('ddflg', 1)
            ->where('yearcode', FiscalYear::yearcode())
            ->when($request->filled('from'), fn ($q) => $q->whereDate('tdate', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('tdate', '<=', $request->input('to')))
            ->orderByDesc('tid')
            ->paginate(10)
            ->withQueryString();

        return view('discards.queue', ['discards' => $discards]);
    }

    /** Entry screen (legacy add_material_discard.php). */
    public function create(): View
    {
        $this->authorize('post-transactions');

        return view('discards.create', [
            'classifications' => Classification::query()
                ->orderBy('classification')
                ->get(['classification_id', 'classification']),
        ]);
    }

    /**
     * Damage-stock availability for an item (legacy
     * getuser_discard_slocshow.php): latest damage-ledger row per location
     * with a positive balance.
     *
     * @return array<int, array<string, int|float>>
     */
    public function availability(int $item): JsonResponse
    {
        $this->authorize('post-transactions');

        return response()->json(['ok' => true, 'availability' => $this->damageRowsFor($item)]);
    }

    /**
     * Save one discard line (AJAX, legacy getuser_discard3.php): with no
     * discard id the header is created first (trid=0 branch), then the
     * per-item row (totals) and one sloc row per selected damage row.
     */
    public function storeLine(Request $request): JsonResponse
    {
        $this->authorize('post-transactions');

        $data = $this->validateLine($request);

        [$discard, $line] = DB::transaction(function () use ($data) {
            $discard = isset($data['discard_id'])
                ? $this->findOpenDiscard((int) $data['discard_id'])
                : null;

            $discard ??= $this->createHeader($data);

            $line = $this->saveLine($discard, $data);

            Audit::log('discard.md', 'line.create', $discard, null, [
                'item' => $line->items_id,
                'rows' => count($data['rows']),
            ]);

            return [$discard, $line];
        });

        return response()->json([
            'ok' => true,
            'discard_id' => $discard->tid,
            'redirect' => route('discards.workspace', $discard),
            'did' => $line->did,
            'item_id' => $line->items_id,
        ]);
    }

    /** Replace one item line (delete-and-reinsert of its sloc rows). */
    public function updateLine(Request $request, int $did): JsonResponse
    {
        $this->authorize('post-transactions');

        $data = $this->validateLine($request);

        [$discard, $line] = DB::transaction(function () use ($data, $did) {
            $discard = $this->findOpenDiscard((int) $data['discard_id']);

            $item = DiscardItem::query()->where('did', $did)->where('did_s', $discard->tid)->first();
            abort_if($item === null, 404, 'Nothing to edit for this line.');
            abort_unless((int) $item->items_id === (int) $data['items_id'], 422, 'Line item mismatch.');

            DiscardSloc::query()->where('discard_id', $did)->delete();
            $item->delete();

            $line = $this->saveLine($discard, $data);

            Audit::log('discard.md', 'line.update', $discard, null, [
                'item' => $line->items_id,
                'rows' => count($data['rows']),
            ]);

            return [$discard, $line];
        });

        return response()->json([
            'ok' => true,
            'discard_id' => $discard->tid,
            'did' => $line->did,
            'item_id' => $line->items_id,
        ]);
    }

    /** Remove one item line with its sloc rows (legacy deleterec 'DD'). */
    public function deleteLine(Request $request, int $did): JsonResponse
    {
        $this->authorize('post-transactions');

        $deleted = DB::transaction(function () use ($request, $did) {
            $discard = $this->findOpenDiscard((int) $request->input('discard_id'));

            $item = DiscardItem::query()->where('did', $did)->where('did_s', $discard->tid)->first();
            if ($item === null) {
                return false;
            }

            DiscardSloc::query()->where('discard_id', $did)->delete();
            $item->delete();

            Audit::log('discard.md', 'line.delete', $discard, ['item' => (int) $item->items_id]);

            return true;
        });

        abort_unless($deleted, 404, 'Nothing to delete for this line.');

        return response()->json(['ok' => true]);
    }

    /** The workspace: header state, item lines with their sloc rows, add form. */
    public function workspace(Discard $discard): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($discard);

        return view('discards.workspace', [
            'discard' => $discard,
            'lines' => $this->linesOf($discard),
            'classifications' => Classification::query()
                ->orderBy('classification')
                ->get(['classification_id', 'classification']),
        ]);
    }

    /** Edit header fields while open (legacy preview-screen header update). */
    public function updateHeader(Request $request, Discard $discard): RedirectResponse
    {
        $this->authorize('post-transactions');
        $this->assertOpen($discard);
        $this->assertSameYear($discard);

        $data = $this->validateHeader($request);

        $discard->fill($data);
        $discard->save();

        Audit::log('discard.md', 'header.update', $discard);

        return back()->with('success', 'Header updated.');
    }

    /**
     * Final post (legacy add_discard_str_preview.php frm_action=submit):
     * one damage-ledger row per discard_slocs row (verbatim math above),
     * reorder pass, commit dd_code/ncode + ddflg, gate-pass row.
     * Transactional and idempotent — legacy was neither.
     */
    public function post(Discard $discard): RedirectResponse
    {
        $this->authorize('post-transactions');

        if ((int) $discard->ddflg === 1) {
            return redirect()->route('discards.show', $discard)
                ->with('error', 'This discard has already been posted.');
        }

        $this->assertSameYear($discard);

        $yearcode = FiscalYear::yearcode();

        $posted = DB::transaction(function () use ($discard, $yearcode) {
            $slocs = DiscardSloc::query()->where('discard_trid', $discard->tid)->get();
            abort_unless($slocs->isNotEmpty(), 422, 'This discard has no lines.');

            $trdate = (string) $discard->tdate;

            foreach ($slocs as $sloc) {
                $source = StockLedgerDamage::query()->where('stld_id', $sloc->discard_rowid)->first();
                abort_if($source === null, 422, "Source damage row {$sloc->discard_rowid} no longer exists.");

                // Legacy's scoped Empty flip (see restoreLegacyEmptyScope).
                $posted = StockLedgerService::post([
                    'direction' => 'out',
                    'damage' => true,
                    'yearcode' => $yearcode,
                    'trtype' => 'Discard',
                    'trsubtype' => 'MD',
                    'trid' => $discard->tid,
                    'partyid' => (string) $discard->party_name,
                    'trdate' => $trdate,
                    'classid' => (int) $source->stld_trclassid,
                    'item_id' => (int) $source->stld_tritemid,
                    'whid' => (int) $source->stld_whid,
                    'binid' => (int) $source->stld_binid,
                    'subbinid' => (int) $source->stld_subbinid,
                    'ups' => (int) $sloc->ups_discard,
                    'qty' => (float) $sloc->qty_discard,
                ]);

                // The transaction re-checks the balance against the row
                // read INSIDE it (validateLine ran before the transaction
                // opened — this closes the TOCTOU gap).
                abort_unless(
                    (float) $sloc->qty_discard <= (float) $source->stld_balqty + 0.001
                    && (int) $sloc->ups_discard <= (int) $source->stld_balups,
                    422,
                    "Discard quantity for row {$sloc->discard_rowid} exceeds its damage balance."
                );

                // Legacy's scoped Empty flip (see restoreLegacyEmptyScope).
                $this->restoreLegacyEmptyScope($source, (int) $source->stld_subbinid, (float) $posted->stld_balqty);
            }

            // Committed serials + posted marker (legacy MAX+1, per year).
            $discard->dd_code = DocumentNumber::next('discard', $yearcode);
            $discard->ncode = DocumentNumber::next('discard.n', $yearcode);
            $discard->ddflg = 1;
            $discard->save();

            // Gate movement (legacy tbl_gate insert: gpcode MAX+1,
            // trid "MD{dd_code}").
            GatePass::query()->create([
                'gpcode' => DocumentNumber::next('gatepass', $yearcode),
                'trid' => 'MD'.(int) $discard->dd_code,
                'yearcode' => $yearcode,
            ]);

            Audit::log('discard.md', 'post', $discard, null, [
                'dd_code' => $discard->dd_code,
                'rows' => $slocs->count(),
            ]);

            return $discard;
        });

        return redirect()
            ->route('discards.show', $posted)
            ->with('success', 'Discard posted: '.DocumentNumber::pretty('discard', (int) $posted->dd_code, $yearcode).'. Stock updated.');
    }

    /**
     * Legacy add_discard_str_preview.php Empty semantics, verbatim:
     *
     *   when the moved item's damage balance hit zero, collect the DISTINCT
     *   (wh, bin, sub-bin) locations hosting OTHER items of the same
     *   classification (stld_trclassid = class AND stld_tritemid != item),
     *   take the latest row per location regardless of item, and count the
     *   locations whose latest row still holds stld_balqty > 0; only when
     *   that count is 0 does the sub-bin flip to 'Empty'.
     *
     * StockLedgerService::post has already applied its UNCONDITIONAL Empty
     * flip (balqty == 0); this restores 'Good' when the scoped check says
     * the sub-bin is still occupied — the good-ledger writers (issues,
     * ITI) have no such cross-item check, so the helper lives here.
     */
    private function restoreLegacyEmptyScope(StockLedgerDamage $sourceRow, int $subbinid, float $postedBalqty): void
    {
        // Legacy checks the just-written out row's resulting balance (its
        // writer loops on balqty), not the baseline row the UI picked.
        if ($postedBalqty !== 0.0) {
            return;
        }

        $occupied = StockLedgerDamage::query()
            ->select('stld_whid', 'stld_binid', 'stld_subbinid')
            ->where('stld_trclassid', (int) $sourceRow->stld_trclassid)
            ->where('stld_tritemid', '!=', (int) $sourceRow->stld_tritemid)
            ->groupBy('stld_whid', 'stld_binid', 'stld_subbinid')
            ->get()
            ->filter(function ($loc) {
                $latest = StockLedgerDamage::query()
                    ->where('stld_whid', $loc->stld_whid)
                    ->where('stld_binid', $loc->stld_binid)
                    ->where('stld_subbinid', $loc->stld_subbinid)
                    ->orderByDesc('stld_id')
                    ->first();

                return $latest !== null && (float) $latest->stld_balqty > 0;
            })
            ->isNotEmpty();

        if ($occupied) {
            DB::table('sub_bins')->where('sid', $subbinid)->update(['status' => 'Good']);
        }
    }

    /** Detail screen (legacy add_discard_str_view.php). */
    public function show(Discard $discard): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($discard);

        return view('discards.show', [
            'discard' => $discard,
            'lines' => $this->linesOf($discard),
        ]);
    }

    // ------------------------------------------------------------------

    /** Items of one classification for the entry screen dropdown. */
    public function items(int $classification): JsonResponse
    {
        $this->authorize('post-transactions');

        $items = Item::query()
            ->where('classification_id', $classification)
            ->where('actstatus', 'Active')
            ->orderBy('stores_item')
            ->get(['items_id', 'stores_item', 'uom']);

        return response()->json(['ok' => true, 'items' => $items]);
    }

    // ------------------------------------------------------------------

    /**
     * Latest damage-ledger row per location with a positive balance — the
     * selectable discard rows for an item.
     *
     * @return array<int, array<string, int|float>>
     */
    private function damageRowsFor(int $itemId): array
    {
        return StockLedgerDamage::query()
            ->select('stld_whid', 'stld_binid', 'stld_subbinid')
            ->where('stld_tritemid', $itemId)
            ->groupBy('stld_whid', 'stld_binid', 'stld_subbinid')
            ->get()
            ->map(function ($loc) use ($itemId) {
                $latest = StockLedgerDamage::query()
                    ->where('stld_tritemid', $itemId)
                    ->where('stld_whid', $loc->stld_whid)
                    ->where('stld_binid', $loc->stld_binid)
                    ->where('stld_subbinid', $loc->stld_subbinid)
                    ->orderByDesc('stld_id')
                    ->first();

                if ($latest === null || (float) $latest->stld_balqty <= 0) {
                    return null;
                }

                return [
                    'stld_id' => (int) $latest->stld_id,
                    'whid' => (int) $latest->stld_whid,
                    'binid' => (int) $latest->stld_binid,
                    'subbinid' => (int) $latest->stld_subbinid,
                    'ups' => (int) $latest->stld_balups,
                    'qty' => (float) $latest->stld_balqty,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The workspace's line view: per item, its sloc rows with names.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linesOf(Discard $discard): array
    {
        return DiscardItem::query()
            ->where('did_s', $discard->tid)
            ->orderBy('did')
            ->get()
            ->map(function (DiscardItem $item) {
                $rows = DiscardSloc::query()
                    ->where('discard_id', $item->did)
                    ->orderBy('discardsloc_id')
                    ->get()
                    ->map(fn (DiscardSloc $r) => [
                        'discardsloc_id' => (int) $r->discardsloc_id,
                        'stld_id' => (int) $r->discard_rowid,
                        'whid' => (int) $r->whid,
                        'binid' => (int) $r->binid,
                        'subbinid' => (int) $r->subbin,
                        'ups_discard' => (int) $r->ups_discard,
                        'qty_discard' => (float) $r->qty_discard,
                        'ups_balance' => (int) $r->ups_balance,
                        'qty_balance' => (float) $r->qty_balance,
                    ])
                    ->all();

                return [
                    'did' => (int) $item->did,
                    'items_id' => (int) $item->items_id,
                    'item_name' => optional(Item::query()->find($item->items_id))->stores_item ?? '—',
                    'uom' => (string) $item->uom,
                    'type' => (string) $item->type,
                    'ups' => (int) $item->ups,
                    'qty' => (float) $item->qty,
                    'rows' => $rows,
                ];
            })
            ->all();
    }

    /** Validation for a line save: header + item + per-row discard amounts. */
    private function validateLine(Request $request): array
    {
        $data = $request->validate([
            'discard_id' => ['nullable', 'integer'],
            'tdate' => ['required', 'date'],
            'drno' => ['nullable', 'string', 'max:50'],
            'party_name' => ['required', 'string', 'max:250'],
            'address' => ['nullable', 'string', 'max:1000'],
            'address1' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:50'],
            'pin' => ['nullable', 'integer', 'max:999999999'],
            'state' => ['nullable', 'string', 'max:50'],
            'phoneno' => ['nullable', 'string', 'max:20'],
            'tmode' => ['nullable', 'string', 'max:50'],
            'tname' => ['nullable', 'string', 'max:50'],
            'lrno' => ['nullable', 'string', 'max:50'],
            'vno' => ['nullable', 'string', 'max:50'],
            'cname' => ['nullable', 'string', 'max:50'],
            'dcno' => ['nullable', 'string', 'max:50'],
            'pmode' => ['nullable', 'string', 'max:50'],
            'pname' => ['nullable', 'string', 'max:250'],
            'rettyp' => ['nullable', 'string', 'max:20'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'classification_id' => ['required', 'integer'],
            'items_id' => ['required', 'integer'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.stld_id' => ['required', 'integer'],
            'rows.*.ups_discard' => ['required', 'integer', 'min:0', 'max:999999'],
            'rows.*.qty_discard' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
        ]);

        // Each discard row must be a real damage-ledger row of the item
        // with enough balance (legacy client-side upschk/qtychk — the port
        // enforces it server-side at save and post).
        foreach ($data['rows'] as $i => $row) {
            $source = StockLedgerDamage::query()->where('stld_id', $row['stld_id'])->first();
            abort_if($source === null, 422, "Damage row {$row['stld_id']} no longer exists.");
            abort_unless((int) $source->stld_tritemid === (int) $data['items_id'], 422,
                "Damage row {$row['stld_id']} belongs to a different item.");
            abort_unless(
                (float) $row['qty_discard'] <= (float) $source->stld_balqty + 0.001
                && (int) $row['ups_discard'] <= (int) $source->stld_balups,
                422,
                "Discard quantity for row {$row['stld_id']} exceeds its damage balance."
            );
        }

        return $data;
    }

    /** Header-only validation for updateHeader. */
    private function validateHeader(Request $request): array
    {
        return $request->validate([
            'tdate' => ['required', 'date'],
            'drno' => ['nullable', 'string', 'max:50'],
            'party_name' => ['required', 'string', 'max:250'],
            'address' => ['nullable', 'string', 'max:1000'],
            'address1' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:50'],
            'pin' => ['nullable', 'integer', 'max:999999999'],
            'state' => ['nullable', 'string', 'max:50'],
            'phoneno' => ['nullable', 'string', 'max:20'],
            'tmode' => ['nullable', 'string', 'max:50'],
            'tname' => ['nullable', 'string', 'max:50'],
            'lrno' => ['nullable', 'string', 'max:50'],
            'vno' => ['nullable', 'string', 'max:50'],
            'cname' => ['nullable', 'string', 'max:50'],
            'dcno' => ['nullable', 'string', 'max:50'],
            'pmode' => ['nullable', 'string', 'max:50'],
            'pname' => ['nullable', 'string', 'max:250'],
            'rettyp' => ['nullable', 'string', 'max:20'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** Create the header on first line post (legacy trid=0 branch). */
    private function createHeader(array $data): Discard
    {
        $discard = Discard::query()->create([
            'tdate' => $data['tdate'],
            'drno' => $data['drno'] ?? null,
            'party_name' => $data['party_name'],
            'address' => $data['address'] ?? null,
            'address1' => $data['address1'] ?? null,
            'city' => $data['city'] ?? null,
            'pin' => $data['pin'] ?? null,
            'state' => $data['state'] ?? null,
            'phoneno' => $data['phoneno'] ?? null,
            'tmode' => $data['tmode'] ?? null,
            'tname' => $data['tname'] ?? null,
            'lrno' => $data['lrno'] ?? null,
            'vno' => $data['vno'] ?? null,
            'cname' => $data['cname'] ?? null,
            'dcno' => $data['dcno'] ?? null,
            'pmode' => $data['pmode'] ?? null,
            'pname' => $data['pname'] ?? null,
            'rettyp' => $data['rettyp'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'yearcode' => FiscalYear::yearcode(),
            'ddrole' => (string) (Auth::user()?->getAuthIdentifier() ?? ''),
            'ddflg' => 0,
        ]);

        // Legacy stored tcode = the document id on the header row.
        $discard->tcode = $discard->tid;
        $discard->save();

        Audit::log('discard.md', 'open', $discard);

        return $discard;
    }

    /**
     * Persist one item line: the per-item row (totals, as legacy's
     * post-save UPDATE set) plus one sloc row per submitted damage row.
     */
    private function saveLine(Discard $discard, array $data): DiscardItem
    {
        $item = Item::query()->find((int) $data['items_id']);
        abort_if($item === null, 422, 'Item not found.');
        abort_unless((int) $item->classification_id === (int) $data['classification_id'], 422,
            'The item does not belong to the selected classification.');
        abort_unless($item->actstatus === 'Active', 422, 'The item is inactive.');

        $discardItem = DiscardItem::query()->create([
            'did_s' => $discard->tid,
            'calssification_id' => (int) $data['classification_id'],
            'items_id' => (int) $data['items_id'],
            'uom' => (string) $item->uom,
            'ups' => array_sum(array_map(fn ($r) => (int) $r['ups_discard'], $data['rows'])),
            'qty' => array_sum(array_map(fn ($r) => (float) $r['qty_discard'], $data['rows'])),
            'type' => $data['rettyp'] ?? 'damage',
            'remark' => $data['remarks'] ?? null,
        ]);

        foreach ($data['rows'] as $row) {
            $source = StockLedgerDamage::query()->where('stld_id', $row['stld_id'])->first();

            DiscardSloc::query()->create([
                'discard_type' => 'MD',
                'discard_trid' => $discard->tid,
                'discard_id' => (int) $discardItem->did,
                'classification_id' => (int) $data['classification_id'],
                'item_id' => (int) $data['items_id'],
                'whid' => (int) $source->stld_whid,
                'binid' => (int) $source->stld_binid,
                'subbin' => (int) $source->stld_subbinid,
                'qty_discard' => (float) $row['qty_discard'],
                'ups_discard' => (int) $row['ups_discard'],
                // Legacy stored the submitted (client-computed) balance
                // columns; the port stores the authoritative ledger values.
                'qty_balance' => (float) $source->stld_balqty - (float) $row['qty_discard'],
                'ups_balance' => (int) $source->stld_balups - (int) $row['ups_discard'],
                'discard_rowid' => (int) $row['stld_id'],
                'eid' => 0,
            ]);
        }

        return $discardItem;
    }

    /** Find this year's open discard by id; posted documents are immutable. */
    private function findOpenDiscard(int $discardId): Discard
    {
        $discard = Discard::query()
            ->where('tid', $discardId)
            ->where('yearcode', FiscalYear::yearcode())
            ->first();

        abort_if($discard === null, 404, 'Discard workspace not found.');
        $this->assertOpen($discard);

        return $discard;
    }

    private function assertOpen(Discard $discard): void
    {
        abort_if((int) $discard->ddflg === 1, 422, 'This discard has already been posted and is immutable.');
    }

    private function assertSameYear(Discard $discard): void
    {
        abort_unless(strcasecmp((string) $discard->yearcode, FiscalYear::yearcode()) === 0, 404,
            'Discard workspace not found.');
    }
}
