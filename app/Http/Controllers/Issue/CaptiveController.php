<?php

namespace App\Http\Controllers\Issue;

use App\Http\Controllers\Controller;
use App\Models\Captive;
use App\Models\CaptiveItem;
use App\Models\CaptiveSloc;
use App\Models\Classification;
use App\Models\Item;
use App\Models\Party;
use App\Models\StockLedgerGood;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\EIssueStatus;
use App\Support\FiscalYear;
use App\Support\StockLedgerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Captive consumption (internal CC — legacy add_internalcc.php /
 * getuser_capetdupdate.php / add_cc_preview.php). A parallel family to the
 * issue types: the header lives in `captives` (legacy tbl_captive), lines in
 * `captive_items` (tbl_captivesub) and the distribution in `captive_slocs`
 * (tbl_captive_sloc). Differences from the issue family, preserved:
 *
 *  - No indent reference: the line carries only the item condition
 *    (`type`, e.g. 'good') and a line remark; the line quantity is NOT
 *    entered — it is computed as the sum of the distribution rows
 *    (legacy `update tbl_captivesub set ups='$totups', qty='$totqty'`).
 *  - The header may reference a party master (party_id) or carry free-form
 *    party details (party_name/address/city/... , legacy txt12=No branch).
 *  - trtype 'CC', trsubtype 'CC' in the ledger; committed serial cc_code
 *    + ncode (counters `captive.vendor` / `captive.vendor.n`), ccflg=1.
 *
 * Port deltas (deliberate, documented): the post is transactional and
 * idempotent (legacy could double-post or go negative); balance coverage is
 * enforced server-side at save and again at post time (legacy: JS only);
 * numbering uses the race-safe document_counters.
 */
class CaptiveController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('post-transactions');

        $captives = Captive::query()
            ->where('yearcode', FiscalYear::yearcode())
            ->when(request('stage') === EIssueStatus::OPEN, fn ($q) => $q->where('ccflg', '!=', 1)->orWhereNull('ccflg'))
            ->when(request('stage') === EIssueStatus::POSTED, fn ($q) => $q->where('ccflg', 1))
            ->orderByDesc('tid')
            ->paginate(20)
            ->withQueryString();

        return view('issues.cc.index', ['captives' => $captives]);
    }

    /** Entry screen (legacy add_internalcc.php). */
    public function create(): View
    {
        $this->authorize('post-transactions');

        return view('issues.cc.create', [
            'classifications' => Classification::query()->orderBy('classification')->get(['classification_id', 'classification']),
            'parties' => Party::query()->orderBy('business_name')->get(['p_id', 'business_name']),
        ]);
    }

    /**
     * Add a line (AJAX, legacy getuser_capetdupdate.php). With no captive
     * id the header is created first (legacy trid=0 branch).
     */
    public function storeLine(Request $request): JsonResponse
    {
        $this->authorize('post-transactions');

        $data = $this->validateLine($request);

        $captive = isset($data['captive_id'])
            ? $this->findOpenCaptive((int) $data['captive_id'])
            : null;

        $result = DB::transaction(function () use ($data, &$captive) {
            $captive ??= $this->createHeader($data);

            $line = $this->saveLine($captive, $data);

            Audit::log('cc.consumption', 'line.create', $line, null, [
                'item' => $data['items_id'], 'qty' => $data['total_qty'],
            ]);

            return $this->lineState($line, $captive);
        });

        return response()->json([
            'ok' => true,
            'captive_id' => $captive->tid,
            'redirect' => route('issues.cc.workspace', $captive),
            'line' => $result,
        ]);
    }

    /** Replace one line + distribution (legacy edtupdate delete-and-reinsert). */
    public function updateLine(Request $request, CaptiveItem $line): JsonResponse
    {
        $this->authorize('post-transactions');

        $captive = $this->findOpenCaptive((int) $line->id_in);
        $data = $this->validateLine($request);

        $result = DB::transaction(function () use ($captive, $line, $data) {
            CaptiveSloc::query()
                ->where('issue_trid', $captive->tid)
                ->where('isue_id', $line->eid)
                ->delete();

            $updated = $this->saveLine($captive, $data, $line);

            Audit::log('cc.consumption', 'line.update', $updated, null, [
                'item' => $data['items_id'], 'qty' => $data['total_qty'],
            ]);

            return $this->lineState($updated, $captive);
        });

        return response()->json(['ok' => true, 'line' => $result]);
    }

    /** Remove one line + distribution (legacy getuser_issue_ccdelete.php). */
    public function deleteLine(CaptiveItem $line): JsonResponse
    {
        $this->authorize('post-transactions');

        $captive = $this->findOpenCaptive((int) $line->id_in);

        $deleted = DB::transaction(function () use ($captive, $line) {
            CaptiveSloc::query()
                ->where('issue_trid', $captive->tid)
                ->where('isue_id', $line->eid)
                ->delete();

            Audit::log('cc.consumption', 'line.delete', $captive, ['item' => $line->items_id]);

            return $line->delete();
        });

        abort_unless($deleted, 404, 'Nothing to delete.');

        return response()->json(['ok' => true]);
    }

    /** The workspace: saved lines, add-line form, final post button. */
    public function workspace(Captive $captive): View
    {
        $this->authorize('post-transactions');
        abort_unless($captive->yearcode === FiscalYear::yearcode(), 404);

        $lines = $captive->items()->get()->map(fn (CaptiveItem $line) => $this->lineState($line, $captive));

        return view('issues.cc.workspace', [
            'captive' => $captive,
            'lines' => $lines,
            'classifications' => Classification::query()->orderBy('classification')->get(['classification_id', 'classification']),
        ]);
    }

    /** Edit the header while open (legacy add_cc_preview.php GET update). */
    public function updateHeader(Request $request, Captive $captive): RedirectResponse
    {
        $this->authorize('post-transactions');
        abort_unless($captive->yearcode === FiscalYear::yearcode(), 404);
        abort_if($this->isPosted($captive), 422, 'Posted transactions are immutable.');

        $data = $request->validate($this->headerRules());

        $captive->fill($this->headerFields($data) + [
            'remarks' => $data['remarks'] ?? $captive->remarks,
            'rettyp' => $data['rettyp'] ?? $captive->rettyp,
        ]);
        $captive->save();

        Audit::log('cc.consumption', 'header.update', $captive);

        return back()->with('success', 'Header updated.');
    }

    /**
     * Final post (legacy add_cc_preview.php frm_action=submit): writes every
     * saved sloc row through StockLedgerService (trtype/trsubtype 'CC'),
     * assigns cc_code/ncode, sets ccflg=1. Transactional and idempotent.
     */
    public function post(Captive $captive): RedirectResponse
    {
        $this->authorize('post-transactions');
        abort_unless($captive->yearcode === FiscalYear::yearcode(), 404);

        if ($this->isPosted($captive)) {
            return redirect()->route('issues.cc.show', $captive)
                ->with('error', 'This transaction has already been posted.');
        }

        $yearcode = FiscalYear::yearcode();
        $partyid = (string) ($captive->party_id ?: $captive->party_name ?: 0);

        $posted = DB::transaction(function () use ($captive, $yearcode, $partyid) {
            $lines = $captive->items()->get();
            abort_unless($lines->isNotEmpty(), 422, 'This transaction has no item lines.');

            foreach ($lines as $line) {
                $slocs = CaptiveSloc::query()
                    ->where('issue_trid', $captive->tid)
                    ->where('isue_id', $line->eid)
                    ->get();
                abort_unless($slocs->isNotEmpty(), 422,
                    'Line for item '.$line->items_id.' has no stock-location distribution yet.');

                $sum = (float) $slocs->sum('qty_issue');
                abort_unless(abs($sum - (float) $line->qty) < 0.001, 422,
                    'Line distribution ('.$sum.') no longer matches the line quantity ('.$line->qty.').');

                foreach ($slocs as $sloc) {
                    $ledger = StockLedgerGood::query()->find((int) $sloc->issue_rowid);
                    abort_if($ledger === null, 422, 'Stock at a chosen location changed; reopen the workspace.');
                    abort_unless((float) $ledger->stlg_balqty + 0.001 >= (float) $sloc->qty_issue, 422,
                        'Insufficient stock at a chosen location; the balances changed since saving.');
                }

                foreach ($slocs as $sloc) {
                    StockLedgerService::post([
                        'direction' => 'out',
                        'yearcode' => $yearcode,
                        'trtype' => 'CC',
                        'trsubtype' => 'CC',
                        'trid' => $captive->tid,
                        'partyid' => $partyid,
                        'trdate' => $captive->tdate,
                        'classid' => $sloc->classification_id,
                        'item_id' => $sloc->item_id,
                        'whid' => (int) $sloc->whid,
                        'binid' => (int) $sloc->binid,
                        'subbinid' => (int) $sloc->subbin,
                        'ups' => (int) $sloc->ups_issue,
                        'qty' => (float) $sloc->qty_issue,
                    ]);
                }

                StockLedgerService::applyReorderFlag((int) $line->items_id);
            }

            $captive->cc_code = $this->primeCommitted($yearcode);
            $captive->ncode = $this->primeNote($yearcode);
            $captive->ccflg = 1;
            $captive->save();

            Audit::log('cc.consumption', 'post', $captive, null, [
                'cc_code' => $captive->cc_code,
                'ncode' => $captive->ncode,
                'lines' => $lines->count(),
            ]);

            return $captive;
        });

        return redirect()
            ->route('issues.cc.show', $posted)
            ->with('success', 'Captive consumption posted: CC'.$posted->cc_code.'. Stock updated.');
    }

    /** Detail screen (legacy select_cc_op.php / cc_issue_note_print.php). */
    public function show(Captive $captive): View
    {
        $this->authorize('post-transactions');
        abort_unless($captive->yearcode === FiscalYear::yearcode(), 404);

        $captive->load(['items.slocs']);
        $party = $captive->party_id ? Party::query()->find($captive->party_id) : null;

        return view('issues.cc.show', [
            'captive' => $captive,
            'party' => $party,
        ]);
    }

    // ------------------------------------------------------------------

    private function isPosted(Captive $captive): bool
    {
        return (int) $captive->ccflg === 1;
    }

    /** Find this year's open transaction, abort when missing/posted. */
    private function findOpenCaptive(int $tid): Captive
    {
        $captive = Captive::query()
            ->where('tid', $tid)
            ->where('yearcode', FiscalYear::yearcode())
            ->first();

        abort_if($captive === null, 404, 'Captive-consumption workspace not found.');
        abort_if($this->isPosted($captive), 422, 'This transaction has already been posted and is immutable.');

        return $captive;
    }

    /** Validation for a line post, including per-transaction header fields. */
    private function validateLine(Request $request): array
    {
        $data = $request->validate($this->headerRules() + [
            'captive_id' => ['nullable', 'integer'],
            'classification_id' => ['required', 'integer'],
            'items_id' => ['required', 'integer'],
            'uom' => ['required', 'string', 'max:50'],
            'type' => ['required', 'string', 'max:50'],       // item condition, e.g. 'good'
            'line_remarks' => ['nullable', 'string', 'max:100'],
            'slocs' => ['required', 'array', 'min:1'],
            'slocs.*.stlg_id' => ['required', 'integer'],
            'slocs.*.ups' => ['required', 'integer', 'min:0', 'max:999999'],
            'slocs.*.qty' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
        ]);

        $item = Item::query()->find((int) $data['items_id']);
        abort_if($item === null, 422, 'Item not found.');
        abort_unless((int) $item->classification_id === (int) $data['classification_id'], 422,
            'The selected item does not belong to the selected classification.');
        abort_unless($item->actstatus === 'Active', 422, 'The item is inactive.');

        // Each distribution row must fit within the live ledger balance it
        // was offered against (legacy checked in JS only).
        foreach ($data['slocs'] as $i => $sloc) {
            $ledger = StockLedgerGood::query()->find((int) $sloc['stlg_id']);
            abort_if($ledger === null, 422, "SLOC row {$i} no longer exists.");
            abort_if((int) $ledger->stlg_tritemid !== (int) $data['items_id'], 422,
                "SLOC row {$i} belongs to a different item.");
            abort_unless((float) $sloc['qty'] <= (float) $ledger->stlg_balqty + 0.001, 422,
                "SLOC row {$i}: issue quantity exceeds the available balance.");
        }

        // The line quantity is the distribution sum (legacy totqty update).
        $data['total_qty'] = round(array_sum(array_column($data['slocs'], 'qty')), 3);
        $data['total_ups'] = array_sum(array_column($data['slocs'], 'ups'));

        return $data;
    }

    /** Header validation — party master OR free-form party details. */
    private function headerRules(): array
    {
        return [
            'tdate' => ['required', 'date'],
            'contactno' => ['nullable', 'string', 'max:20'],
            'party_mode' => ['required', 'in:master,manual'],
            'party_id' => ['nullable', 'required_if:party_mode,master', 'integer', 'exists:parties,p_id'],
            'party_name' => ['nullable', 'required_if:party_mode,manual', 'string', 'max:250'],
            'address' => ['nullable', 'string', 'max:100'],
            'address1' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'max:10'],
            'state' => ['nullable', 'string', 'max:50'],
            'rettyp' => ['required', 'string', 'max:50'],
            'tmode' => ['required', 'in:Transport,Courier,By Hand'],
            'tname' => ['nullable', 'string', 'max:100'],
            'lrno' => ['nullable', 'string', 'max:50'],
            'vno' => ['nullable', 'string', 'max:100'],
            'pmode' => ['nullable', 'string', 'max:100'],
            'cname' => ['nullable', 'string', 'max:50'],
            'docketno' => ['nullable', 'string', 'max:50'],
            'pname' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** Map validated header fields onto the captives columns (legacy names). */
    private function headerFields(array $data): array
    {
        $fields = [
            'tdate' => $data['tdate'],
            'contactno' => $data['contactno'] ?? null,
            'tmode' => $data['tmode'],
            'tname' => $data['tname'] ?? null,
            'lrno' => $data['lrno'] ?? null,
            'vno' => $data['vno'] ?? null,
            'pmode' => $data['pmode'] ?? null,
            'cname' => $data['cname'] ?? null,
            'docketno' => $data['docketno'] ?? null,
            'pname' => $data['pname'] ?? null,
            'rettyp' => $data['rettyp'],
        ];

        if ($data['party_mode'] === 'master') {
            return $fields + ['party_id' => (int) $data['party_id']];
        }

        return $fields + [
            'party_id' => null,
            'party_name' => $data['party_name'],
            'address' => $data['address'] ?? null,
            'address1' => $data['address1'] ?? null,
            'city' => $data['city'] ?? null,
            'pin' => $data['pin'] ?? null,
            'state' => $data['state'] ?? null,
        ];
    }

    /** Create the header on first line post (legacy trid=0 branch). */
    private function createHeader(array $data): Captive
    {
        $yearcode = FiscalYear::yearcode();

        $captive = Captive::query()->create($this->headerFields($data) + [
            'code' => DocumentNumber::next('captive.workspace', $yearcode),
            'yearcode' => $yearcode,
            'ccflg' => 0,
            'ccrole' => (string) auth()->id(),
            'remarks' => $data['remarks'] ?? null,
        ]);

        Audit::log('cc.consumption', 'open', $captive);

        return $captive;
    }

    /**
     * Persist one line + its sloc rows (create or replace). CC has no
     * entered quantity: the line's ups/qty are the distribution sums
     * (legacy `update tbl_captivesub set ups=totups, qty=totqty`).
     */
    private function saveLine(Captive $captive, array $data, ?CaptiveItem $line = null): CaptiveItem
    {
        $line ??= new CaptiveItem(['id_in' => $captive->tid]);

        $line->fill([
            'classification_id' => (int) $data['classification_id'],
            'items_id' => (int) $data['items_id'],
            'uom' => (string) $data['uom'],
            'ups' => (int) $data['total_ups'],
            'qty' => (float) $data['total_qty'],
            'remarks' => $data['line_remarks'] ?? null,
            'type' => (string) $data['type'],
        ]);
        $line->save();

        foreach ($data['slocs'] as $sloc) {
            $ledger = StockLedgerGood::query()->find((int) $sloc['stlg_id']);

            CaptiveSloc::query()->create([
                'issue_type' => 'captive',
                'issue_trid' => $captive->tid,
                'isue_id' => $line->eid,
                'classification_id' => (int) $data['classification_id'],
                'item_id' => (int) $data['items_id'],
                'whid' => $ledger->stlg_whid,
                'binid' => $ledger->stlg_binid,
                'subbin' => $ledger->stlg_subbinid,
                'qty_issue' => (float) $sloc['qty'],
                'ups_issue' => (int) $sloc['ups'],
                'qty_balance' => (float) $ledger->stlg_balqty - (float) $sloc['qty'],
                'ups_balance' => (int) $ledger->stlg_balups - (int) $sloc['ups'],
                'issue_rowid' => $ledger->stlg_id,
                'eid' => $line->eid,
            ]);
        }

        return $line;
    }

    /** Committed serial (cc_code), bootstrapped from legacy MAX. */
    private function primeCommitted(string $yearcode): int
    {
        if (DocumentNumber::current('captive.vendor', $yearcode) === 0) {
            DocumentNumber::prime('captive.vendor', $yearcode,
                (int) Captive::query()->where('yearcode', $yearcode)->max('cc_code'));
        }

        return DocumentNumber::next('captive.vendor', $yearcode);
    }

    /** Note serial (ncode), bootstrapped from legacy MAX. */
    private function primeNote(string $yearcode): int
    {
        if (DocumentNumber::current('captive.vendor.n', $yearcode) === 0) {
            DocumentNumber::prime('captive.vendor.n', $yearcode,
                (int) Captive::query()->where('yearcode', $yearcode)->max('ncode'));
        }

        return DocumentNumber::next('captive.vendor.n', $yearcode);
    }

    /**
     * Per-line state for the workspace: availability rows (latest positive
     * balance per location) and the saved distribution (legacy preview
     * queried tbl_captive_sloc by issue_trid + isue_id).
     */
    private function lineState(CaptiveItem $line, Captive $captive): array
    {
        $locations = StockLedgerGood::query()
            ->select('stlg_whid', 'stlg_binid', 'stlg_subbinid')
            ->where('stlg_tritemid', $line->items_id)
            ->where('stlg_trclassid', $line->classification_id)
            ->groupBy('stlg_whid', 'stlg_binid', 'stlg_subbinid')
            ->orderBy('stlg_whid')
            ->orderBy('stlg_binid')
            ->orderBy('stlg_subbinid')
            ->get()
            ->map(function ($loc) use ($line) {
                $latest = StockLedgerGood::query()
                    ->where('stlg_tritemid', $line->items_id)
                    ->where('stlg_whid', $loc->stlg_whid)
                    ->where('stlg_binid', $loc->stlg_binid)
                    ->where('stlg_subbinid', $loc->stlg_subbinid)
                    ->orderByDesc('stlg_id')
                    ->first();

                return [
                    'key' => $loc->stlg_whid.'-'.$loc->stlg_binid.'-'.$loc->stlg_subbinid,
                    'stlg_id' => $latest?->stlg_id,
                    'whid' => (int) $loc->stlg_whid,
                    'binid' => (int) $loc->stlg_binid,
                    'subbinid' => (int) $loc->stlg_subbinid,
                    'ups' => (int) ($latest->stlg_balups ?? 0),
                    'qty' => (float) ($latest->stlg_balqty ?? 0),
                ];
            })
            ->filter(fn ($l) => $l['qty'] > 0 || $l['ups'] > 0)
            ->values();

        $distributed = CaptiveSloc::query()
            ->where('issue_trid', $captive->tid)
            ->where('isue_id', $line->eid)
            ->get()
            ->map(fn ($s) => [
                'issuesloc_id' => $s->issuesloc_id,
                'whid' => (int) $s->whid,
                'binid' => (int) $s->binid,
                'subbinid' => (int) $s->subbin,
                'ups' => (int) $s->ups_issue,
                'qty' => (float) $s->qty_issue,
            ]);

        return [
            'line' => $line,
            'available' => $locations,
            'distributed' => $distributed,
            'distributed_qty' => (float) $distributed->sum('qty'),
        ];
    }
}
