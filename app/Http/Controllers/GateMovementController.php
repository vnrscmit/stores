<?php

namespace App\Http\Controllers;

use App\Models\Classification;
use App\Models\Dtog;
use App\Models\DtogItem;
use App\Models\Gtod;
use App\Models\GtodItem;
use App\Models\Item;
use App\Models\Party;
use App\Models\PartyLedger;
use App\Models\StockLedgerDamage;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
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
 * The Good→Damage / Damage→Good conversion — legacy Transaction/add_g.php
 * (G2D queue) / add_d.php (D2G queue) / getuser_gdupdate.php +
 * getuser_gdetdupdate.php (G2D line save) / getuser_dgupdate.php (D2G line
 * save) / getuser_gd_slocshow.php + getuser_dg_slocshowd.php (stock
 * availability) / add_gtod_preview.php + add_dtog_preview.php (final
 * post). One document converts ONE item, slot by slot: the source SLOC is
 * referenced by its ledger row (rowid), the destination SLOCs are chosen
 * freely (legacy: numeric wh/bin/sub-bin inputs) and stored as document
 * rows with their own op/bal bookkeeping. gdflg/dgflg = 0 while open.
 *
 * Final post (verbatim add_gtod_preview.php / add_dtog_preview.php):
 *
 *  G2D (per gtod_items row, trtype/subtype 'GD' on both ledgers):
 *   - the SOURCE ledger row is re-resolved to the live latest row at its
 *     location (legacy re-queried MAX(id) per location at post time); the
 *     summed tr columns of the rows sharing its rowid are the movement;
 *   - good ledger: ONE out row per rowid group — trpartyid = the header's
 *     party (legacy wrote it on every GD good row), opening = the live
 *     latest balance, bal = op − tr (good balups carries the legacy
 *     max(0, …) UNSIGNED clamp; the port reuses the service's writer);
 *   - damage ledger: ONE in row per gtod_items row — opening = the latest
 *     damage balance at that location (0 when none, as legacy), bal =
 *     op + tr with the ES-style normalization (balqty > 0 && balups == 0
 *     → 1; balqty == 0 → 0); subbin status → 'Damage' unconditionally
 *     (legacy's own GD writer does exactly that);
 *   - on a full source drain the service's UNCONDITIONAL Empty flip
 *     applies (the legacy scoped cross-item check was dead code — its
 *     counter `$totnog` was undefined — so `cntg` was always 0);
 *   - ONE party-ledger row per document (legacy summed the whole
 *     tbl_gtod_sub): damage = Σ tr, bal = opening − damage, all other
 *     sides 0 (legacy wrote ex/sh as 0);
 *   - the reorder pass runs once per document — good ledger only,
 *     verbatim degenerate semantics (see applyReorderFlag).
 *
 *  D2G (per dtog_items row, trtype/subtype 'DG' on both ledgers):
 *   - damage ledger: ONE out row per rowid group — party id 0 (legacy D2G
 *     rows carry none), balqty = op − tr, but balups = op VERBATIM (the
 *     legacy insert keeps stld_balups = the opening — UPS is NOT
 *     decremented; preserved deliberately, then unsigned-clamped);
 *   - good ledger: ONE in row per dtog_items row with the same ES-style
 *     normalization and bal = op + tr; subbin status → 'Good';
 *   - on a full source drain the same unconditional Empty flip applies
 *     (same dead-code check, same undefined `$totnog`);
 *   - NO party-ledger row, NO reorder pass (the legacy D2G writer has
 *     neither), no gate-pass row (legacy G2D/D2G never touch tbl_gate).
 *
 * Commit: G2D — gcode/ncode from the gtod/gtod.n counters; D2G — dcode/
 * ncode from dtog/dtog.n (legacy MAX+1 per yearcode), flag = 1.
 * The whole post is one DB transaction — legacy was not.
 *
 * Deviations from the legacy UI, deliberate and documented:
 *  - the legacy queues (add_g.php / add_d.php) DELETED every open
 *    document (and their rows) on page load, making open workspaces
 *    unreachable after navigation; the port keeps open workspaces and
 *    offers edit semantics (header update + delete-and-reinsert of rows,
 *    unposted only), as in the discard/ES slices;
 *  - the header's classification/item are fixed after the first line is
 *    saved (the port keeps the legacy update behaviour but the views
 *    treat the workspace as the only edit surface for rows).
 */
class GateMovementController extends Controller
{
    use AuthorizesRequests;

    /** The G2D queue (legacy add_g.php listing): posted documents. */
    public function index(Request $request): View
    {
        $this->authorize('post-transactions');

        $gtods = Gtod::query()
            ->where('gdflg', 1)
            ->where('yearcode', FiscalYear::yearcode())
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->input('to')))
            ->orderByDesc('gid')
            ->paginate(10)
            ->withQueryString();

        return view('gatemovements.queue', ['direction' => 'g2d', 'documents' => $gtods]);
    }

    /** The D2G queue (legacy add_d.php listing): posted documents. */
    public function indexD2g(Request $request): View
    {
        $this->authorize('post-transactions');

        $dtogs = Dtog::query()
            ->where('dgflg', 1)
            ->where('yearcode', FiscalYear::yearcode())
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->input('to')))
            ->orderByDesc('did')
            ->paginate(10)
            ->withQueryString();

        return view('gatemovements.queue', ['direction' => 'd2g', 'documents' => $dtogs]);
    }

    /** Entry screen (legacy getuser_gdupdate.php / getuser_dgupdate.php form). */
    public function create(Request $request): View
    {
        $this->authorize('post-transactions');

        return view('gatemovements.create', [
            'direction' => $request->route('direction') === 'd2g' ? 'd2g' : 'g2d',
            'classifications' => Classification::query()
                ->orderBy('classification')
                ->get(['classification_id', 'classification']),
            'parties' => Party::query()
                ->orderBy('business_name')
                ->get(['p_id', 'business_name']),
        ]);
    }

    /**
     * Save the whole document (legacy getuser_gdupdate.php / getuser_
     * dgupdate.php: header + destination rows in one AJAX POST; edits
     * re-run it). With no document id the header is created first
     * (code = next draft serial, flag = 0).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('post-transactions');

        $validated = $request->validate([
            'direction' => ['required', 'in:g2d,d2g'],
            'gtod_id' => ['nullable', 'integer'],
            'dtog_id' => ['nullable', 'integer'],
            'tdate' => ['required', 'date'],
            'classification_id' => ['required', 'integer'],
            'items_id' => ['required', 'integer'],
            'uom' => ['required', 'string', 'max:20'],
            'party_id' => ['nullable', 'integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.rowid' => ['required', 'integer'],
            'rows.*.whid' => ['required', 'integer'],
            'rows.*.binid' => ['required', 'integer'],
            'rows.*.subbinid' => ['required', 'integer'],
            'rows.*.ups' => ['required', 'integer', 'min:0'],
            'rows.*.qty' => ['required', 'numeric', 'min:0'],
        ], [
            'rows.min' => 'Select at least one source SLOC row and a destination to convert.',
        ]);

        $data = $this->validateParty($request, $validated);
        $damage = $data['direction'] === 'g2d';

        [$document, $created] = DB::transaction(function () use ($data, $damage) {
            $document = $damage
                ? (isset($data['gtod_id']) ? $this->findOpenGtod((int) $data['gtod_id']) : null)
                : (isset($data['dtog_id']) ? $this->findOpenDtog((int) $data['dtog_id']) : null);

            $created = $document === null;

            if ($document === null) {
                $document = $this->createHeader($data, $damage);
            } else {
                // Legacy getuser_gdupdate.php rewrote the header on edit.
                $document->fill([
                    'date' => $data['tdate'],
                    'classification_id' => (int) $data['classification_id'],
                    'items_id' => (int) $data['items_id'],
                    'uom' => (string) $data['uom'],
                    'remarks' => $data['remarks'] ?? null,
                ]);
                if ($damage) {
                    $document->party_id = (int) ($data['party_id'] ?? 0);
                }
                $document->save();
            }

            $this->assertOpen($document, $damage);
            $this->assertSameYear($document, $damage);

            // Legacy edit semantics: delete-and-reinsert of the rows.
            if ($damage) {
                GtodItem::query()->where('gid', $document->gid)->delete();
            } else {
                DtogItem::query()->where('did', $document->did)->delete();
            }

            $this->saveRows($document, $data, $damage);

            Audit::log($damage ? 'gatemovement.g2d' : 'gatemovement.d2g', $created ? 'open' : 'lines.replace', $document, null, [
                'rows' => count($data['rows']),
            ]);

            return [$document, $created];
        });

        return response()->json([
            'ok' => true,
            'gtod_id' => $damage ? (int) $document->gid : null,
            'dtog_id' => $damage ? null : (int) $document->did,
            'redirect' => $damage
                ? route('gatemovements.workspace', $document)
                : route('gatemovements.workspace-d2g', $document),
        ]);
    }

    /** The G2D workspace: header state, saved rows, the add-rows form. */
    public function workspace(Gtod $gtod): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($gtod, true);

        return view('gatemovements.workspace', [
            'direction' => 'g2d',
            'document' => $gtod,
            'lines' => $this->linesOf($gtod, true),
            'parties' => Party::query()->orderBy('business_name')->get(['p_id', 'business_name']),
        ]);
    }

    /** The D2G workspace. */
    public function workspaceD2g(Dtog $dtog): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($dtog, false);

        return view('gatemovements.workspace', [
            'direction' => 'd2g',
            'document' => $dtog,
            'lines' => $this->linesOf($dtog, false),
            'parties' => collect(),
        ]);
    }

    /** Edit header fields while open (legacy getuser_gdupdate.php header update). */
    public function updateHeader(Request $request, Gtod $gtod): RedirectResponse
    {
        return $this->updateHeaderFor($request, $gtod, true);
    }

    /** Edit header fields while open (D2G variant). */
    public function updateHeaderD2g(Request $request, Dtog $dtog): RedirectResponse
    {
        return $this->updateHeaderFor($request, $dtog, false);
    }

    /**
     * Final post (legacy add_gtod_preview.php / add_dtog_preview.php
     * frm_action=submit): the verbatim ledger math above, the G2D party
     * ledger + reorder pass, commit serials + flag. Transactional and
     * idempotent — legacy was neither.
     */
    public function post(Gtod $gtod): RedirectResponse
    {
        return $this->postDocument($gtod, true);
    }

    /** Final post (D2G variant). */
    public function postD2g(Dtog $dtog): RedirectResponse
    {
        return $this->postDocument($dtog, false);
    }

    /**
     * The posted document / note view (legacy gtodnote.php / dtognote.php
     * shape): header, per-row source and destination balances.
     */
    public function show(Gtod $gtod): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($gtod, true);

        return view('gatemovements.show', [
            'direction' => 'g2d',
            'document' => $gtod,
            'lines' => $this->linesOf($gtod, true),
        ]);
    }

    /** The posted D2G document. */
    public function showD2g(Dtog $dtog): View
    {
        $this->authorize('post-transactions');
        $this->assertSameYear($dtog, false);

        return view('gatemovements.show', [
            'direction' => 'd2g',
            'document' => $dtog,
            'lines' => $this->linesOf($dtog, false),
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
     * Source stock availability (legacy getuser_gd_slocshow.php for G2D,
     * getuser_dg_slocshowd.php for D2G): latest ledger row per location
     * with a positive balance, in the direction's source ledger. Each
     * listed row carries the opening (ups/qty) the post will start from.
     */
    public function availability(Request $request, int $item): JsonResponse
    {
        $this->authorize('post-transactions');

        $validated = $request->validate([
            'direction' => ['required', 'in:g2d,d2g'],
        ]);

        return response()->json(['ok' => true, 'rows' => $this->stockRowsFor($item, $validated['direction'])]);
    }

    /** The G2D party is validated against the parties master. */
    private function validateParty(Request $request, array $validated): array
    {
        $partyId = (int) ($validated['party_id'] ?? 0);

        if ($validated['direction'] === 'g2d' && $partyId === 0) {
            abort(422, 'The party is required for a Good to Damage movement.');
        }

        if ($partyId !== 0 && Party::query()->where('p_id', $partyId)->doesntExist()) {
            abort(422, 'The selected party does not exist.');
        }

        if ($validated['direction'] === 'd2g') {
            // Legacy D2G never carries a party; drop it defensively.
            $validated['party_id'] = 0;
        }

        return $validated;
    }

    /**
     * Latest ledger row per location with a positive balance — the
     * selectable source rows (getuser_gd_slocshow / getuser_dg_slocshowd
     * semantics: the MAX(id) row per item x location must itself hold
     * balqty > 0).
     *
     * @return array<int, array<string, int|float>>
     */
    private function stockRowsFor(int $itemId, string $direction): array
    {
        $damage = $direction === 'g2d';
        $sc = $damage ? 'stlg' : 'stld';
        $ledger = $damage ? StockLedgerGood::query() : StockLedgerDamage::query();

        return $ledger
            ->select("{$sc}_whid", "{$sc}_binid", "{$sc}_subbinid")
            ->where("{$sc}_tritemid", $itemId)
            ->groupBy("{$sc}_whid", "{$sc}_binid", "{$sc}_subbinid")
            ->get()
            ->map(function ($loc) use ($itemId, $damage, $sc) {
                $latest = StockLedgerService::latestRow(
                    $itemId,
                    (int) $loc->{"{$sc}_whid"},
                    (int) $loc->{"{$sc}_binid"},
                    (int) $loc->{"{$sc}_subbinid"},
                    ! $damage
                );

                if ($latest === null || (float) $latest->{"{$sc}_balqty"} <= 0) {
                    return null;
                }

                return [
                    'rowid' => (int) $latest->{"{$sc}_id"},
                    'whid' => (int) $latest->{"{$sc}_whid"},
                    'binid' => (int) $latest->{"{$sc}_binid"},
                    'subbinid' => (int) $latest->{"{$sc}_subbinid"},
                    'ups' => (int) $latest->{"{$sc}_balups"},
                    'qty' => (float) $latest->{"{$sc}_balqty"},
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** Header fields + row-level guards, shared by both directions. */
    private function saveRows(Gtod|Dtog $document, array $data, bool $damage): void
    {
        // The SOURCE rows live in the good ledger for a G2D and in the
        // damage ledger for a D2G (the destination ledger is the opposite).
        $sc = $damage ? 'stlg' : 'stld';

        $item = Item::query()->find((int) $data['items_id']);
        abort_if($item === null, 422, 'Item not found.');
        abort_unless((int) $item->classification_id === (int) $data['classification_id'], 422,
            'The item does not belong to the selected classification.');
        abort_unless($item->actstatus === 'Active', 422, 'The item is inactive.');

        $rowids = array_map(fn ($row) => (int) $row['rowid'], $data['rows']);

        // Legacy allowed up to two destination slots per source row (the
        // line save's slot-1/slot-2 inserts share one rowid), so rows may
        // repeat a rowid — the group total is what must fit the source.
        $groupQty = [];
        $groupUps = [];

        foreach ($data['rows'] as $row) {
            $source = $damage
                ? StockLedgerGood::query()->where('stlg_id', $row['rowid'])->first()
                : StockLedgerDamage::query()->where('stld_id', $row['rowid'])->first();
            abort_if($source === null, 422, "Referenced ledger row {$row['rowid']} no longer exists.");
            abort_unless((int) $source->{"{$sc}_tritemid"} === (int) $data['items_id'], 422,
                "Ledger row {$row['rowid']} does not belong to the selected item.");

            // Destination SLOCs are free numeric inputs in the legacy UI;
            // validate the triple exists (ITI destination semantics).
            $subbin = SubBin::query()
                ->where('sid', (int) $row['subbinid'])
                ->where('binid', (int) $row['binid'])
                ->where('whid', (int) $row['whid'])
                ->first();
            abort_if($subbin === null, 422,
                "Row for ledger row {$row['rowid']}: the destination sub-bin does not belong to the bin.");

            $rowid = (int) $row['rowid'];
            $groupQty[$rowid] = ($groupQty[$rowid] ?? 0.0) + (float) $row['qty'];
            $groupUps[$rowid] = ($groupUps[$rowid] ?? 0) + (int) $row['ups'];
        }

        foreach ($groupQty as $rowid => $qty) {
            $source = $damage
                ? StockLedgerGood::query()->where('stlg_id', $rowid)->first()
                : StockLedgerDamage::query()->where('stld_id', $rowid)->first();
            abort_unless(
                $qty <= (float) $source->{"{$sc}_balqty"} + 0.001
                && $groupUps[$rowid] <= (int) $source->{"{$sc}_balups"},
                422,
                "Conversion for ledger row {$rowid} exceeds its balance."
            );
        }

        foreach ($data['rows'] as $row) {
            // The destination's own op/bal bookkeeping (legacy getuser_
            // gdupdate.php / getuser_dgupdate.php): the ES-style balance
            // pair the line save computed for the destination SLOC.
            $balups = (int) $row['ups'];
            $balqty = (float) $row['qty'];
            if ($balqty > 0 && $balups == 0) {
                $balups = 1;
            }
            if ($balqty == 0) {
                $balups = 0;
            }

            $attributes = [
                'classification_id' => (int) $data['classification_id'],
                'items_id' => (int) $data['items_id'],
                'whid' => (int) $row['whid'],
                'binid' => (int) $row['binid'],
                'subbinid' => (int) $row['subbinid'],
                'opups' => 0,
                'opqty' => 0.0,
                'ups' => (int) $row['ups'],
                'qty' => (float) $row['qty'],
                'balups' => $balups,
                'balqty' => $balqty,
                'rowid' => (int) $row['rowid'],
            ];

            if ($damage) {
                GtodItem::query()->create($attributes + ['gid' => $document->gid]);
            } else {
                DtogItem::query()->create($attributes + ['did' => $document->did]);
            }
        }
    }

    /**
     * One ledger pair per document row group (verbatim preview math). For
     * each rowid: resolve the live latest source row (legacy re-queried
     * MAX(id) at post time — a mismatch aborts), sum the tr columns of
     * the group, then post the source out row and the destination in row.
     *
     * @param  array<int, array<string, int|float>>  $lines
     */
    private function postRowGroups(
        Gtod|Dtog $document,
        array $lines,
        bool $damage,
        string $trtype,
        string $trdate,
        string $yearcode,
    ): void {
        $sc = $damage ? 'stlg' : 'stld';

        $groups = [];

        foreach ($lines as $line) {
            $groups[$line['rowid']][] = $line;
        }

        foreach ($groups as $rowid => $group) {
            $source = $damage
                ? StockLedgerGood::query()->where('stlg_id', $rowid)->first()
                : StockLedgerDamage::query()->where('stld_id', $rowid)->first();
            abort_if($source === null, 422, "Referenced ledger row {$rowid} no longer exists.");

            $latest = StockLedgerService::latestRow(
                (int) $source->{"{$sc}_tritemid"},
                (int) $source->{"{$sc}_whid"},
                (int) $source->{"{$sc}_binid"},
                (int) $source->{"{$sc}_subbinid"},
                ! $damage
            );

            abort_if($latest === null || (int) $latest->{"{$sc}_id"} !== (int) $source->{"{$sc}_id"},
                422,
                'The stock at the referenced location changed while this document was open. Reload the rows and save again.'
            );

            $trups = array_sum(array_map(fn ($l) => (int) $l['ups'], $group));
            $trqty = array_sum(array_map(fn ($l) => (float) $l['qty'], $group));

            $this->postSourceOut($document, $latest, $trups, $trqty, $trtype, $trdate, $yearcode, $damage);

            foreach ($group as $line) {
                $this->postDestinationIn($document, $line, $trtype, $trdate, $yearcode, $damage);
            }
        }
    }

    /**
     * The source out row (legacy good-ledger G2D insert / damage-ledger
     * D2G insert). The service's writer reproduces both, including the
     * UPS normalization and the unconditional Empty flip on balqty == 0
     * — the legacy scoped cross-item check was dead code ($totnog), so
     * the flip WAS unconditional in the GD/DG writers too. The one thing
     * the service cannot express is the D2G damage quirk: stld_balups =
     * op (UPS NOT decremented); that row is written by hand below.
     */
    private function postSourceOut(
        Gtod|Dtog $document,
        object $source,
        int $trups,
        float $trqty,
        string $trtype,
        string $trdate,
        string $yearcode,
        bool $damage,
    ): void {
        // The OUT row goes to the ledger opposite the destination: good
        // ledger for a G2D, damage ledger for a D2G.
        $partyid = $damage ? (string) $document->party_id : '0';

        if (! $damage) {
            // D2G, verbatim legacy quirk: the DG damage out row keeps
            // stld_balups = the opening (UPS not decremented), unsigned-
            // clamped the way the column silently was.
            $opups = (int) $source->stld_balups;
            $opqty = (float) $source->stld_balqty;
            $balqty = max(0.0, $opqty - $trqty);
            $balups = max(0, $opups);

            $row = new StockLedgerDamage;
            $row->yearcode = $yearcode;
            $row->stld_trtype = $trtype;
            $row->stld_trsubtype = $trtype;
            $row->stld_trid = (int) $document->did;
            $row->stld_trpartyid = $partyid;
            $row->stld_trdate = $trdate;
            $row->stld_trclassid = (int) $source->stld_trclassid;
            $row->stld_tritemid = (int) $source->stld_tritemid;
            $row->stld_whid = (int) $source->stld_whid;
            $row->stld_binid = (int) $source->stld_binid;
            $row->stld_subbinid = (int) $source->stld_subbinid;
            $row->stld_opups = max(0, $opups);
            $row->stld_opqty = $opqty;
            $row->stld_trups = $trups;
            $row->stld_trqty = $trqty;
            $row->stld_balups = $balups;
            $row->stld_balqty = $balqty;
            $row->save();

            // The service's Empty flip (balqty == 0), applied verbatim.
            if ($balqty == 0.0) {
                SubBin::query()->where('sid', (int) $source->stld_subbinid)->update(['status' => 'Empty']);
            }

            return;
        }

        // G2D good out row: legacy stlg_balups = op − tr with the
        // UNSIGNED clamp — exactly the service's writer.
        StockLedgerService::post([
            'direction' => 'out',
            'damage' => false,
            'yearcode' => $yearcode,
            'trtype' => $trtype,
            'trsubtype' => $trtype,
            'trid' => (int) $document->gid,
            'partyid' => $partyid,
            'trdate' => $trdate,
            'classid' => (int) $source->stlg_trclassid,
            'item_id' => (int) $source->stlg_tritemid,
            'whid' => (int) $source->stlg_whid,
            'binid' => (int) $source->stlg_binid,
            'subbinid' => (int) $source->stlg_subbinid,
            'ups' => $trups,
            'qty' => $trqty,
        ]);
    }

    /**
     * The destination in row (legacy damage-ledger G2D insert /
     * good-ledger D2G insert): bal = op + tr with the ES-style UPS
     * normalization, subbin status flipped unconditionally ('Damage' for
     * a G2D destination, 'Good' for a D2G one — the legacy GD/DG writers
     * never scoped the flip).
     */
    private function postDestinationIn(
        Gtod|Dtog $document,
        array $line,
        string $trtype,
        string $trdate,
        string $yearcode,
        bool $damage,
    ): void {
        $partyid = $damage ? (string) $document->party_id : '0';

        $latest = StockLedgerService::latestRow(
            (int) $line['items_id'],
            (int) $line['whid'],
            (int) $line['binid'],
            (int) $line['subbinid'],
            $damage
        );

        $c = $damage ? 'stld' : 'stlg';

        // Opening = the latest balance at the destination (0 when none —
        // the legacy insert defaulted $opups/$opqty to 0 exactly then).
        $opups = (int) ($latest->{"{$c}_balups"} ?? 0);
        $opqty = (float) ($latest->{"{$c}_balqty"} ?? 0);

        $ups = (int) $line['ups'];
        $qty = (float) $line['qty'];

        // ES-style normalization (verbatim getuser_gdupdate.php /
        // getuser_dgupdate.php line-save rules).
        $balups = $opups > 0 ? $opups + $ups : $ups;
        $balqty = $opqty > 0 ? $opqty + $qty : $qty;

        $c = $damage ? 'stld' : 'stlg';

        $row = $damage ? new StockLedgerDamage : new StockLedgerGood;
        $row->yearcode = $yearcode;
        $row->{"{$c}_trtype"} = $trtype;
        $row->{"{$c}_trsubtype"} = $trtype;
        $row->{"{$c}_trid"} = $damage ? (int) $document->gid : (int) $document->did;
        $row->{"{$c}_trpartyid"} = $partyid;
        $row->{"{$c}_trdate"} = $trdate;
        $row->{"{$c}_trclassid"} = (int) $line['classification_id'];
        $row->{"{$c}_tritemid"} = (int) $line['items_id'];
        $row->{"{$c}_whid"} = (int) $line['whid'];
        $row->{"{$c}_binid"} = (int) $line['binid'];
        $row->{"{$c}_subbinid"} = (int) $line['subbinid'];
        $row->{"{$c}_opups"} = max(0, $opups);
        $row->{"{$c}_opqty"} = $opqty;
        $row->{"{$c}_trups"} = $ups;
        $row->{"{$c}_trqty"} = $qty;
        $row->{"{$c}_balups"} = max(0, $balups);
        $row->{"{$c}_balqty"} = max(0.0, $balqty);
        $row->save();

        SubBin::query()->where('sid', (int) $line['subbinid'])->update([
            'status' => $damage ? 'Damage' : 'Good',
        ]);
    }

    /**
     * The G2D party-ledger row (verbatim add_gtod_preview.php): ONE row
     * per document summing the whole tbl_gtod_sub — damage = Σ tr, bal =
     * opening − damage, every other side 0 (legacy wrote ex/sh as 0).
     */
    private function postPartyLedger(Gtod $gtod, float $trqty, int $trups, string $trdate, string $yearcode): void
    {
        $latest = PartyLedger::query()
            ->where('pldg_trpartyid', (int) $gtod->party_id)
            ->where('pldg_trclassid', (int) $gtod->classification_id)
            ->where('pldg_tritemid', (int) $gtod->items_id)
            ->orderByDesc('pldg_id')
            ->first();

        $balanceups = (int) ($latest->pldg_trbalups ?? 0) - $trups;
        $balanceqty = (float) ($latest->pldg_trbalqty ?? 0.0) - $trqty;

        PartyLedger::query()->create([
            'pldg_trtype' => 'GD',
            'pldg_trsubtype' => 'GD',
            'pldg_trid' => (int) $gtod->gid,
            'pldg_trpartyid' => (int) $gtod->party_id,
            'pldg_trdate' => $trdate,
            'pldg_trclassid' => (int) $gtod->classification_id,
            'pldg_tritemid' => (int) $gtod->items_id,
            // Legacy wrote every side except damage as 0 (dc/good/ex/sh).
            'pldg_trdcups' => 0,
            'pldg_trdcqty' => 0.0,
            'pldg_trgoodups' => 0,
            'pldg_trgoodqty' => 0.0,
            'pldg_trdamageups' => $trups,
            'pldg_trdamageqty' => $trqty,
            'pldg_trexqty' => 0.0,
            'pldg_trshqty' => 0.0,
            'pldg_trbalups' => $balanceups,
            'pldg_trbalqty' => $balanceqty,
            'yearcode' => $yearcode,
        ]);
    }

    /** The G2D reorder pass — verbatim (good ledger only; degenerate
     * sum semantics, see StockLedgerService::applyReorderFlag). */
    private function postReorder(int $itemId): void
    {
        StockLedgerService::applyReorderFlag($itemId);
    }

    private function postDocument(Gtod|Dtog $document, bool $damage): RedirectResponse
    {
        $this->authorize('post-transactions');

        if ((int) ($damage ? $document->gdflg : $document->dgflg) === 1) {
            $route = $damage ? 'gatemovements.show' : 'gatemovements.show-d2g';

            return redirect()->route($route, $document)
                ->with('error', 'This gate movement has already been posted.');
        }

        $this->assertSameYear($document, $damage);

        $yearcode = FiscalYear::yearcode();

        $posted = DB::transaction(function () use ($document, $damage, $yearcode) {
            $lines = $damage
                ? GtodItem::query()->where('gid', $document->gid)->orderBy('gdsubid')->get()
                : DtogItem::query()->where('did', $document->did)->orderBy('dgsubid')->get();
            abort_unless($lines->isNotEmpty(), 422, 'This gate movement has no rows.');

            $trdate = (string) $document->date;
            $trtype = $damage ? 'GD' : 'DG';

            $this->postRowGroups(
                $document,
                $lines->map(fn ($l) => [
                    'rowid' => (int) $l->rowid,
                    'whid' => (int) $l->whid,
                    'binid' => (int) $l->binid,
                    'subbinid' => (int) $l->subbinid,
                    'ups' => (int) $l->ups,
                    'qty' => (float) $l->qty,
                    'items_id' => (int) $l->items_id,
                    'classification_id' => (int) $l->classification_id,
                ])->all(),
                $damage,
                $trtype,
                $trdate,
                $yearcode,
            );

            // G2D: the party ledger + reorder pass (the legacy D2G writer
            // has neither).
            if ($damage) {
                $this->postPartyLedger(
                    $document,
                    (float) $lines->sum('qty'),
                    (int) $lines->sum('ups'),
                    $trdate,
                    $yearcode,
                );
                $this->postReorder((int) $document->items_id);
            }

            // Committed serials + posted marker (legacy MAX+1, per year).
            if ($damage) {
                $document->gcode = DocumentNumber::next('gtod', $yearcode);
                $document->ncode = DocumentNumber::next('gtod.n', $yearcode);
                $document->gdflg = 1;
            } else {
                $document->dcode = DocumentNumber::next('dtog', $yearcode);
                $document->ncode = DocumentNumber::next('dtog.n', $yearcode);
                $document->dgflg = 1;
            }
            $document->save();

            Audit::log($damage ? 'gatemovement.g2d' : 'gatemovement.d2g', 'post', $document, null, [
                $damage ? 'gcode' : 'dcode' => $damage ? $document->gcode : $document->dcode,
                'rows' => $lines->count(),
            ]);

            return $document;
        });

        $pretty = $damage
            ? DocumentNumber::pretty('gtod', (int) $posted->gcode, $yearcode)
            : DocumentNumber::pretty('dtog', (int) $posted->dcode, $yearcode);
        $route = $damage ? 'gatemovements.show' : 'gatemovements.show-d2g';

        return redirect()
            ->route($route, $posted)
            ->with('success', 'Gate movement posted: '.$pretty.'. Stock updated.');
    }

    /** Header edit shared by both directions (open documents only). */
    private function updateHeaderFor(Request $request, Gtod|Dtog $document, bool $damage): RedirectResponse
    {
        $this->authorize('post-transactions');
        $this->assertOpen($document, $damage);
        $this->assertSameYear($document, $damage);

        $rules = [
            'tdate' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
        if ($damage) {
            $rules['party_id'] = ['required', 'integer'];
        }

        $data = $request->validate($rules);

        if ($damage) {
            abort_unless(Party::query()->where('p_id', (int) $data['party_id'])->exists(), 422,
                'The selected party does not exist.');
            $document->party_id = (int) $data['party_id'];
        }

        $document->date = $data['tdate'];
        $document->remarks = $data['remarks'] ?? null;
        $document->save();

        Audit::log($damage ? 'gatemovement.g2d' : 'gatemovement.d2g', 'header.update', $document);

        return back()->with('success', 'Header updated.');
    }

    /** Create the header row (legacy getuser_gdupdate.php / getuser_dgupdate.php insert, flag = 0). */
    private function createHeader(array $data, bool $damage): Gtod|Dtog
    {
        $yearcode = FiscalYear::yearcode();

        if ($damage) {
            return Gtod::query()->create([
                'code' => DocumentNumber::next('gtod.draft', $yearcode),
                'date' => $data['tdate'],
                'classification_id' => (int) $data['classification_id'],
                'items_id' => (int) $data['items_id'],
                'uom' => (string) $data['uom'],
                'remarks' => $data['remarks'] ?? null,
                'yearcode' => $yearcode,
                'party_id' => (int) ($data['party_id'] ?? 0),
                'gdflg' => 0,
            ]);
        }

        return Dtog::query()->create([
            'code' => DocumentNumber::next('dtog.draft', $yearcode),
            'date' => $data['tdate'],
            'classification_id' => (int) $data['classification_id'],
            'items_id' => (int) $data['items_id'],
            'uom' => (string) $data['uom'],
            'remarks' => $data['remarks'] ?? null,
            'yearcode' => $yearcode,
            'dgflg' => 0,
        ]);
    }

    /** Find this year's open G2D by id; posted documents are immutable. */
    private function findOpenGtod(int $gid): Gtod
    {
        $gtod = Gtod::query()
            ->where('gid', $gid)
            ->where('yearcode', FiscalYear::yearcode())
            ->first();

        abort_if($gtod === null, 404, 'Gate movement workspace not found.');
        $this->assertOpen($gtod, true);

        return $gtod;
    }

    /** Find this year's open D2G by id; posted documents are immutable. */
    private function findOpenDtog(int $did): Dtog
    {
        $dtog = Dtog::query()
            ->where('did', $did)
            ->where('yearcode', FiscalYear::yearcode())
            ->first();

        abort_if($dtog === null, 404, 'Gate movement workspace not found.');
        $this->assertOpen($dtog, false);

        return $dtog;
    }

    private function assertOpen(Gtod|Dtog $document, bool $damage): void
    {
        $flag = (int) ($damage ? $document->gdflg : $document->dgflg);

        abort_if($flag === 1, 422, 'This gate movement has already been posted and is immutable.');
    }

    private function assertSameYear(Gtod|Dtog $document, bool $damage): void
    {
        abort_unless((string) $document->yearcode === (string) FiscalYear::yearcode(), 422,
            'This document belongs to a different financial year.');
    }

    /** Saved rows with their source and destination locations, for the views. */
    private function linesOf(Gtod|Dtog $document, bool $damage): array
    {
        $sc = $damage ? 'stlg' : 'stld';

        $rows = $damage
            ? GtodItem::query()->where('gid', $document->gid)->orderBy('gdsubid')->get()
            : DtogItem::query()->where('did', $document->did)->orderBy('dgsubid')->get();

        return $rows->map(function (GtodItem|DtogItem $item) use ($damage, $sc) {
            $source = $damage
                ? StockLedgerGood::query()->where('stlg_id', $item->rowid)->first()
                : StockLedgerDamage::query()->where('stld_id', $item->rowid)->first();

            return [
                'subid' => $damage ? (int) $item->gdsubid : (int) $item->dgsubid,
                'rowid' => (int) $item->rowid,
                'src_whid' => $source === null ? null : (int) $source->{"{$sc}_whid"},
                'src_binid' => $source === null ? null : (int) $source->{"{$sc}_binid"},
                'src_subbinid' => $source === null ? null : (int) $source->{"{$sc}_subbinid"},
                'whid' => (int) $item->whid,
                'binid' => (int) $item->binid,
                'subbinid' => (int) $item->subbinid,
                'ups' => (int) $item->ups,
                'qty' => (float) $item->qty,
                'balups' => (int) $item->balups,
                'balqty' => (float) $item->balqty,
                'current_balance' => $source === null
                    ? null
                    : ['ups' => (int) $source->{"{$sc}_balups"}, 'qty' => (float) $source->{"{$sc}_balqty"}],
            ];
        })->all();
    }
}
