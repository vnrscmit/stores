<?php

namespace App\Http\Controllers;

use App\Models\Classification;
use App\Models\Excess;
use App\Models\ExcessItem;
use App\Models\Item;
use App\Models\StockLedgerDamage;
use App\Models\StockLedgerGood;
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
 * The Excess/Shortage adjustment — legacy Transaction/add_e1.php (line
 * save) + add_exsh_preview.php (final submit) + getuser_exsh_slocshow.php
 * (stock availability) + edit_exsh.php (line edit).
 *
 * One document adjusts the stock of ONE item at one or more SLOCs, per
 * ledger row (excesses row, esflg=0 while open; the header `typ` selects
 * the ledger: 'good' or 'damage' — the whole document adjusts one ledger):
 *
 *  1. Workspace: the available ledger rows for the item are listed (legacy
 *     getuser_exsh_slocshow.php: MAX(stlg_id/stld_id) per location with
 *     balqty > 0 on that row) and each row carries an EXCESS pair and a
 *     SHORTAGE pair — the legacy UI emptied one pair as soon as the other
 *     was filled, so a row adjusts up (ES) or down (SH), never both.
 *  2. Legacy saved header + all rows in ONE POST (add_e1.php), the row's
 *     wh/bin/sub-bin copied from the referenced ledger row (excess_items
 *     .rowid = stlg_id/stld_id); the shown post-balance column is the
 *     client-side op ± adjustment (excess adds, shortage subtracts). The
 *     port recomputes that column server-side from the same formula.
 *  3. Final post (add_exsh_preview.php frm_action=submit), per excess_items
 *     row: ONE ledger row in the header's ledger — trtype 'ES', trid = the
 *     excess document id, opening = the referenced row's balance, and
 *       excess side (upsex/qtyex present): subtype 'ES', bal = op + ex;
 *       shortage side (else):                subtype 'SH', bal = op − sh.
 *     (The legacy branch keys on upse==0 && qtye==0 — a row with BOTH
 *     sides empty posts as a shortage with tr = sh = 0. Preserved.)
 *     NO sub-bin status flip — legacy's ES writer never touches tbl_subbin.
 *     NO UPS normalization — the unsigned-column clamp below is the only
 *     guard (legacy inserted the raw values).
 *  4. Reorder pass (verbatim, class-scoped): when the item is srl-tracked
 *     and the summed positive good-ledger balance drops to/below its
 *     reorder level (srl), all of its positive rows are flagged
 *     orstatus='R'. NOTE: the legacy location DISTINCT query filters
 *     `stlg_tritemid != item` before re-checking the same item at those
 *     locations — an effective no-op that degenerates to "sum all
 *     positive balances of the item" (documented in
 *     StockLedgerService::applyReorderFlag, which implements exactly that).
 *  5. Commit: escode/ncode from the excess/excess.n counters (legacy MAX+1
 *     per yearcode), esflg = 1. No gate pass (the legacy ES writer writes
 *     none). No party id: stlg_trpartyid stays NULL (legacy rows do too).
 *  The whole post is one DB transaction — legacy was not.
 *
 * Deviations from the legacy UI, deliberate and documented:
 *  - the legacy home screen (add_shortage.php) DELETED every esflg=0
 *    document (and its rows) on page load, making open workspaces
 *    unreachable after navigation; the port keeps open workspaces and
 *    offers the edit semantics of legacy edit_exsh.php (header update +
 *    delete-and-reinsert of rows, unposted only).
 *  - the legacy view screen (select_es_op.php) filtered
 *    stlg_trsubtype='ES' and so hid shortage rows from the bin status
 *    sheet; the port lists both sides.
 *
 * Server-side guards (the legacy UI checked them in JS only): a row may
 * not carry both sides, the shortage may not exceed the referenced row's
 * balance (re-checked inside the post transaction), a row must belong to
 * the header's item, and a rowid may appear at most once per document.
 */
class ExcessShortageController extends Controller
{
    use AuthorizesRequests;

    /** The queue (legacy add_shortage.php listing): posted documents. */
    public function index(Request $request): View
    {
        $this->authorize('post-transactions');

        $excesses = Excess::query()
            ->where('esflg', 1)
            ->where('yearcode', FiscalYear::yearcode())
            ->when($request->filled('from'), fn ($q) => $q->whereDate('tdate', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('tdate', '<=', $request->input('to')))
            ->orderByDesc('tid')
            ->paginate(10)
            ->withQueryString();

        return view('exshorts.queue', ['excesses' => $excesses]);
    }

    /** Entry screen (legacy add_e.php / add_e1.php form). */
    public function create(): View
    {
        $this->authorize('post-transactions');

        return view('exshorts.create', [
            'classifications' => Classification::query()
                ->orderBy('classification')
                ->get(['classification_id', 'classification']),
        ]);
    }

    /**
     * Save the whole document (legacy add_e1.php: header + all rows in one
     * POST; edits re-run it, legacy edit_exsh.php). With no excess id the
     * header is created first (code = next draft serial, esflg=0).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('post-transactions');

        $data = $this->validateDocument($request);

        [$excess, $created] = DB::transaction(function () use ($data) {
            $excess = isset($data['excess_id'])
                ? $this->findOpenExcess((int) $data['excess_id'])
                : null;

            $created = $excess === null;

            if ($excess === null) {
                $excess = $this->createHeader($data);
            } else {
                // Legacy edit_exsh.php rewrote the header fields too.
                $excess->fill([
                    'tdate' => $data['tdate'],
                    'classification_id' => (int) $data['classification_id'],
                    'items_id' => (int) $data['items_id'],
                    'uom' => (string) $data['uom'],
                    'typ' => $data['typ'],
                    'remarks' => $data['remarks'] ?? null,
                ]);
                $excess->save();
            }

            $this->assertOpen($excess);
            $this->assertSameYear($excess);

            // Legacy edit semantics: delete-and-reinsert of the rows.
            ExcessItem::query()->where('esid', $excess->tid)->delete();

            $this->saveRows($excess, $data);

            Audit::log('adjustment.es', $created ? 'open' : 'lines.replace', $excess, null, [
                'rows' => count($data['rows']),
            ]);

            return [$excess, $created];
        });

        return response()->json([
            'ok' => true,
            'excess_id' => $excess->tid,
            'redirect' => route('exshorts.workspace', $excess),
        ]);
    }

    /** The workspace: header state, saved rows, the add-rows form. */
    public function workspace(Excess $excess): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($excess);

        return view('exshorts.workspace', [
            'excess' => $excess,
            'lines' => $this->linesOf($excess),
            'classifications' => Classification::query()
                ->orderBy('classification')
                ->get(['classification_id', 'classification']),
        ]);
    }

    /** Edit header fields while open (legacy edit_exsh.php header update). */
    public function updateHeader(Request $request, Excess $excess): RedirectResponse
    {
        $this->authorize('post-transactions');
        $this->assertOpen($excess);
        $this->assertSameYear($excess);

        $data = $request->validate([
            'tdate' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $excess->fill($data);
        $excess->save();

        Audit::log('adjustment.es', 'header.update', $excess);

        return back()->with('success', 'Header updated.');
    }

    /**
     * Final post (legacy add_exsh_preview.php frm_action=submit): one
     * ledger row per excess_items row (verbatim math above), the reorder
     * pass, commit escode/ncode + esflg. Transactional and idempotent —
     * legacy was neither.
     */
    public function post(Excess $excess): RedirectResponse
    {
        $this->authorize('post-transactions');

        if ((int) $excess->esflg === 1) {
            return redirect()->route('exshorts.show', $excess)
                ->with('error', 'This excess/shortage has already been posted.');
        }

        $this->assertSameYear($excess);

        $yearcode = FiscalYear::yearcode();

        $posted = DB::transaction(function () use ($excess, $yearcode) {
            $rows = ExcessItem::query()->where('esid', $excess->tid)->orderBy('essubid')->get();
            abort_unless($rows->isNotEmpty(), 422, 'This excess/shortage has no rows.');

            $trdate = (string) $excess->tdate;
            $damage = $excess->typ !== 'good';
            $c = $damage ? 'stld' : 'stlg';

            foreach ($rows as $row) {
                $source = $damage
                    ? StockLedgerDamage::query()->where('stld_id', $row->rowid)->first()
                    : StockLedgerGood::query()->where('stlg_id', $row->rowid)->first();
                abort_if($source === null, 422, "Referenced ledger row {$row->rowid} no longer exists.");

                // Legacy branch (add_exsh_preview.php): the EXCESS side
                // posts when upsex/qtyex are present, otherwise the
                // SHORTAGE side — a row with both sides empty posts a
                // shortage of 0/0. The shortage may not exceed the
                // referenced row's balance (re-checked inside the
                // transaction; saveRows validated it earlier too).
                $isExcess = (int) $row->upsex !== 0 || (float) $row->qtyex !== 0.0;

                if ($isExcess) {
                    $ups = (int) $row->upsex;
                    $qty = (float) $row->qtyex;
                    $subtype = 'ES';
                } else {
                    $ups = (int) $row->upssh;
                    $qty = (float) $row->qtysh;
                    $subtype = 'SH';

                    abort_unless(
                        $qty <= (float) $source->{"{$c}_balqty"} + 0.001 && $ups <= (int) $source->{"{$c}_balups"},
                        422,
                        "Shortage for ledger row {$row->rowid} exceeds its balance."
                    );
                }

                $this->postLedgerRow($excess, $source, $subtype, $ups, $qty, $trdate, $yearcode);
            }

            // Reorder pass (verbatim: good ledger only, class-scoped query
            // degenerating to the item's summed positive balance — see the
            // class docblock; the legacy outer loop iterates headers with
            // a single item each, so one pass per document).
            StockLedgerService::applyReorderFlag((int) $excess->items_id);

            // Commit: escode/ncode from the excess/excess.n counters
            // (legacy MAX+1 per yearcode), esflg = 1.
            $excess->escode = DocumentNumber::next('excess', $yearcode);
            $excess->ncode = DocumentNumber::next('excess.n', $yearcode);
            $excess->esflg = 1;
            $excess->save();

            Audit::log('adjustment.es', 'post', $excess, null, [
                'escode' => $excess->escode,
                'rows' => $rows->count(),
            ]);

            return $excess;
        });

        return redirect()
            ->route('exshorts.show', $posted)
            ->with('success', 'Excess/shortage posted: '.DocumentNumber::pretty('excess', (int) $posted->escode, $yearcode).'. Stock updated.');
    }

    /**
     * The bin status sheet / posted document (legacy select_es_op.php +
     * add_exsh_view.php). Both sides are listed — the legacy screen
     * filtered subtype='ES' and hid shortage rows; deliberate deviation.
     */
    public function show(Excess $excess): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($excess);

        return view('exshorts.show', [
            'excess' => $excess,
            'lines' => $this->linesOf($excess),
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

    /**
     * Stock availability for the entry form (legacy getuser_exsh_slocshow
     * .php): latest ledger row per location with a positive balance, in
     * the ledger the header's `typ` selects. Each listed row carries the
     * opening (ups/qty) the post will start from.
     */
    public function availability(Request $request, int $item): JsonResponse
    {
        $this->authorize('post-transactions');

        $validated = $request->validate([
            'typ' => ['required', 'in:good,damage'],
        ]);

        return response()->json(['ok' => true, 'rows' => $this->stockRowsFor($item, $validated['typ'])]);
    }

    /**
     * Latest ledger row per location with a positive balance — the
     * selectable adjustment rows (getuser_exsh_slocshow semantics: the
     * MAX(id) row per item x location must itself hold balqty > 0).
     *
     * @return array<int, array<string, int|float>>
     */
    private function stockRowsFor(int $itemId, string $typ): array
    {
        $damage = $typ !== 'good';
        $c = $damage ? 'stld' : 'stlg';
        $ledger = $damage ? StockLedgerDamage::query() : StockLedgerGood::query();

        return $ledger
            ->select("{$c}_whid", "{$c}_binid", "{$c}_subbinid")
            ->where("{$c}_tritemid", $itemId)
            ->groupBy("{$c}_whid", "{$c}_binid", "{$c}_subbinid")
            ->get()
            ->map(function ($loc) use ($itemId, $damage, $c) {
                $latest = StockLedgerService::latestRow(
                    $itemId,
                    (int) $loc->{"{$c}_whid"},
                    (int) $loc->{"{$c}_binid"},
                    (int) $loc->{"{$c}_subbinid"},
                    $damage
                );

                if ($latest === null || (float) $latest->{"{$c}_balqty"} <= 0) {
                    return null;
                }

                return [
                    'rowid' => (int) $latest->{"{$c}_id"},
                    'whid' => (int) $latest->{"{$c}_whid"},
                    'binid' => (int) $latest->{"{$c}_binid"},
                    'subbinid' => (int) $latest->{"{$c}_subbinid"},
                    'ups' => (int) $latest->{"{$c}_balups"},
                    'qty' => (float) $latest->{"{$c}_balqty"},
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** Header fields of one ES document (create + workspace edit). */
    private function validateDocument(Request $request): array
    {
        return $request->validate([
            'excess_id' => ['nullable', 'integer'],
            'tdate' => ['required', 'date'],
            'classification_id' => ['required', 'integer'],
            'items_id' => ['required', 'integer'],
            'uom' => ['required', 'string', 'max:20'],
            'typ' => ['required', 'in:good,damage'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.rowid' => ['required', 'integer'],
            'rows.*.upsex' => ['required', 'integer', 'min:0'],
            'rows.*.qtyex' => ['required', 'numeric', 'min:0'],
            'rows.*.upssh' => ['required', 'integer', 'min:0'],
            'rows.*.qtysh' => ['required', 'numeric', 'min:0'],
        ], [
            'rows.min' => 'Select at least one SLOC row to adjust.',
        ]);
    }

    /** Create the header row (legacy add_e1.php insert, esflg=0). */
    private function createHeader(array $data): Excess
    {
        $yearcode = FiscalYear::yearcode();

        // Draft serial: legacy code = MAX(code)+1 per yearcode at creation
        // (add_e1.php), distinct from the committed escode.
        return Excess::query()->create([
            'code' => DocumentNumber::next('excess.draft', $yearcode),
            'tdate' => $data['tdate'],
            'classification_id' => (int) $data['classification_id'],
            'items_id' => (int) $data['items_id'],
            'uom' => (string) $data['uom'],
            'remarks' => $data['remarks'] ?? null,
            'typ' => $data['typ'],
            'yearcode' => $yearcode,
            'esflg' => 0,
        ]);
    }

    /**
     * Persist one row per submitted ledger row (legacy add_e1.php): the
     * location columns are copied from the referenced row, both sides are
     * stored, and the post-balance columns (balups/balqty) keep the
     * client-shown op ± adjustment, recomputed server-side from the same
     * formula the legacy JS used.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveRows(Excess $excess, array $data): void
    {
        $damage = $excess->typ !== 'good';
        $c = $damage ? 'stld' : 'stlg';

        $item = Item::query()->find((int) $data['items_id']);
        abort_if($item === null, 422, 'Item not found.');
        abort_unless((int) $item->classification_id === (int) $data['classification_id'], 422,
            'The item does not belong to the selected classification.');
        abort_unless($item->actstatus === 'Active', 422, 'The item is inactive.');

        $rowids = array_map(fn ($row) => (int) $row['rowid'], $data['rows']);
        abort_unless(count($rowids) === count(array_unique($rowids)), 422,
            'Each SLOC row may only be adjusted once per document.');

        $totups = 0;
        $totqty = 0.0;

        foreach ($data['rows'] as $row) {
            $source = $damage
                ? StockLedgerDamage::query()->where('stld_id', $row['rowid'])->first()
                : StockLedgerGood::query()->where('stlg_id', $row['rowid'])->first();
            abort_if($source === null, 422, "Referenced ledger row {$row['rowid']} no longer exists.");
            abort_unless((int) $source->{"{$c}_tritemid"} === (int) $data['items_id'], 422,
                "Ledger row {$row['rowid']} does not belong to the selected item.");

            $hasExcess = (int) $row['upsex'] !== 0 || (float) $row['qtyex'] !== 0.0;
            $hasShortage = (int) $row['upssh'] !== 0 || (float) $row['qtysh'] !== 0.0;

            abort_unless($hasExcess xor $hasShortage, 422,
                "Ledger row {$row['rowid']} must carry either an excess or a shortage, not both.");

            if ($hasShortage) {
                abort_unless(
                    (float) $row['qtysh'] <= (float) $source->{"{$c}_balqty"} + 0.001
                    && (int) $row['upssh'] <= (int) $source->{"{$c}_balups"},
                    422,
                    "Shortage for ledger row {$row['rowid']} exceeds its balance."
                );
            }

            // The effective side drives the header totals (the side that
            // will post; the legacy totals summed the "chosen" side too).
            $ups = $hasExcess ? (int) $row['upsex'] : (int) $row['upssh'];
            $qty = $hasExcess ? (float) $row['qtyex'] : (float) $row['qtysh'];
            $totups += $ups;
            $totqty += $qty;

            $opups = (int) $source->{"{$c}_balups"};
            $opqty = (float) $source->{"{$c}_balqty"};

            ExcessItem::query()->create([
                'esid' => $excess->tid,
                'whid' => (int) $source->{"{$c}_whid"},
                'binid' => (int) $source->{"{$c}_binid"},
                'subbinid' => (int) $source->{"{$c}_subbinid"},
                'qtyex' => (float) $row['qtyex'],
                'upsex' => (int) $row['upsex'],
                'qtysh' => (float) $row['qtysh'],
                'upssh' => (int) $row['upssh'],
                // Post-balance shown in the legacy UI (balups_/balqty_
                // fields): op + excess / op − shortage.
                'balups' => $hasExcess ? $opups + (int) $row['upsex'] : $opups - (int) $row['upssh'],
                'balqty' => $hasExcess ? $opqty + (float) $row['qtyex'] : $opqty - (float) $row['qtysh'],
                'rowid' => (int) $row['rowid'],
            ]);
        }

        // Header totals (legacy post-save UPDATE, ups = Σ, qty = Σ).
        $excess->ups = $totups;
        $excess->qty = $totqty;
        $excess->save();
    }

    /**
     * Write ONE ledger row (verbatim add_exsh_preview.php insert): trtype
     * 'ES', subtype 'ES'|'SH', trid = the document id, opening = the
     * referenced row's balance, no party id, no UPS normalization, no
     * sub-bin flip. The referenced row is re-resolved against the live
     * latest row at its location (the legacy preview re-queried MAX(id)
     * per location at post time); a mismatch aborts the post.
     */
    private function postLedgerRow(
        Excess $excess,
        StockLedgerGood|StockLedgerDamage $source,
        string $subtype,
        int $ups,
        float $qty,
        string $trdate,
        string $yearcode,
    ): StockLedgerGood|StockLedgerDamage {
        $damage = $excess->typ !== 'good';
        $c = $damage ? 'stld' : 'stlg';

        $latest = StockLedgerService::latestRow(
            (int) $source->{"{$c}_tritemid"},
            (int) $source->{"{$c}_whid"},
            (int) $source->{"{$c}_binid"},
            (int) $source->{"{$c}_subbinid"},
            $damage
        );

        abort_if($latest === null || (int) $latest->{"{$c}_id"} !== (int) $source->{"{$c}_id"},
            422,
            'The stock at the referenced location changed while this document was open. Reload the rows and save again.'
        );

        $opups = (int) $latest->{"{$c}_balups"};
        $opqty = (float) $latest->{"{$c}_balqty"};

        // Verbatim legacy math (no normalization; the shortage is bounded
        // by the referenced row's balance, excess is unbounded upward).
        $balups = $subtype === 'ES' ? $opups + $ups : $opups - $ups;
        $balqty = $subtype === 'ES' ? $opqty + $qty : $opqty - $qty;

        // Legacy wrote the raw values into UNSIGNED columns; the port
        // clamps to 0 the way those columns silently did, so a runaway
        // shortage cannot park a negative balance in the ledger.
        $balups = max(0, $balups);
        $balqty = max(0.0, $balqty);

        $row = $damage ? new StockLedgerDamage : new StockLedgerGood;
        $row->yearcode = $yearcode;
        $row->{"{$c}_trtype"} = 'ES';
        $row->{"{$c}_trsubtype"} = $subtype;
        $row->{"{$c}_trid"} = $excess->tid;
        // Legacy ES rows carry no party id (stlg_trpartyid NULL).
        $row->{"{$c}_trpartyid"} = null;
        $row->{"{$c}_trdate"} = $trdate;
        $row->{"{$c}_trclassid"} = (int) $source->{"{$c}_trclassid"};
        $row->{"{$c}_tritemid"} = (int) $source->{"{$c}_tritemid"};
        $row->{"{$c}_whid"} = (int) $source->{"{$c}_whid"};
        $row->{"{$c}_binid"} = (int) $source->{"{$c}_binid"};
        $row->{"{$c}_subbinid"} = (int) $source->{"{$c}_subbinid"};
        $row->{"{$c}_opups"} = max(0, $opups);
        $row->{"{$c}_opqty"} = $opqty;
        $row->{"{$c}_trups"} = $ups;
        $row->{"{$c}_trqty"} = $qty;
        $row->{"{$c}_balups"} = $balups;
        $row->{"{$c}_balqty"} = $balqty;
        $row->save();

        // NO sub-bin flip: legacy's ES writer never touches tbl_subbin
        // (unlike the arrival/issue/ITI/MD writers).

        return $row;
    }

    /** Find this year's ES document by id; posted documents are immutable. */
    private function findOpenExcess(int $excessId): Excess
    {
        $excess = Excess::query()
            ->where('tid', $excessId)
            ->where('yearcode', FiscalYear::yearcode())
            ->first();

        abort_if($excess === null, 404, 'Excess/shortage workspace not found.');
        $this->assertOpen($excess);

        return $excess;
    }

    private function assertOpen(Excess $excess): void
    {
        abort_if((int) $excess->esflg === 1, 422, 'This excess/shortage has already been posted and is immutable.');
    }

    private function assertSameYear(Excess $excess): void
    {
        abort_unless((string) $excess->yearcode === (string) FiscalYear::yearcode(), 422,
            'This document belongs to a different financial year.');
    }

    /** Saved rows with their referenced locations, for the workspace views. */
    private function linesOf(Excess $excess): array
    {
        $damage = $excess->typ !== 'good';
        $c = $damage ? 'stld' : 'stlg';

        return ExcessItem::query()
            ->where('esid', $excess->tid)
            ->orderBy('essubid')
            ->get()
            ->map(function (ExcessItem $item) use ($damage, $c) {
                $source = $damage
                    ? StockLedgerDamage::query()->where('stld_id', $item->rowid)->first()
                    : StockLedgerGood::query()->where('stlg_id', $item->rowid)->first();

                return [
                    'essubid' => (int) $item->essubid,
                    'rowid' => (int) $item->rowid,
                    'whid' => (int) $item->whid,
                    'binid' => (int) $item->binid,
                    'subbinid' => (int) $item->subbinid,
                    'upsex' => (int) $item->upsex,
                    'qtyex' => (float) $item->qtyex,
                    'upssh' => (int) $item->upssh,
                    'qtysh' => (float) $item->qtysh,
                    'balups' => (int) $item->balups,
                    'balqty' => (float) $item->balqty,
                    'current_balance' => $source === null
                        ? null
                        : ['ups' => (int) $source->{"{$c}_balups"}, 'qty' => (float) $source->{"{$c}_balqty"}],
                ];
            })
            ->all();
    }
}
