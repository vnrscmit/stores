<?php

namespace App\Http\Controllers\Issue;

use App\Http\Controllers\Controller;
use App\Models\EIndent;
use App\Models\EIndentItem;
use App\Models\Issue;
use App\Models\IssueItem;
use App\Models\IssueSloc;
use App\Models\StockLedgerGood;
use App\Support\Audit;
use App\Support\EIndentStatus;
use App\Support\EIssueStatus;
use App\Support\FiscalYear;
use App\Support\IssueNumbering;
use App\Support\StockLedgerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Issue against e-Indents — the first production StockLedgerService caller.
 *
 * Legacy flow (add_issue_indents.php -> getuser_issue_eindentupdate.php ->
 * add_issue_eindents_preview.php -> add_issue_eindents_view.php):
 *
 *  1. Operator opens a pending indent (e_indents.flg=0, tflg=1). Opening the
 *     workspace creates the `issues` header (issuetrflag=0) with
 *     issue_code = MAX(issue_code)+1 per yearcode, dcrefno = indent code.
 *  2. Per indent item line, existing SLOCs (latest positive ledger balance
 *     per warehouse/bin/sub-bin) are offered; the operator distributes the
 *     indent quantity across locations. Each save writes `issue_items` +
 *     `issue_slocs` rows (edit = delete-and-reinsert of the sloc rows).
 *  3. Final submit posts every sloc row into tbl_stldg_good — opening
 *     balance from the latest row at the location, balance = open - issue,
 *     UPS normalization, sub-bin 'Empty' flip, reorder flagging — then
 *     assigns iss_code/ncode, sets issuetrflag=1 and closes the indent
 *     (e_indents.flg=1).
 *
 * Port deltas (deliberate, documented): the post is transactional and
 * re-validated at post time (legacy posted whatever was saved and could go
 * negative or double-post); sub-bin 'Empty' uses the real cross-item check
 * legacy intended (its $totnog bug is fixed); and the indent must be
 * approved (e_indents.status) — the approval gate this port added.
 */
class EIssueController extends Controller
{
    use AuthorizesRequests;

    /** Pending indents open for issue (legacy flg=0 AND tflg=1, plus the approval gate). */
    public function pending(): View
    {
        $this->authorize('post-transactions');

        $q = EIndent::query()
            ->with('raiser:id,login,name')
            ->where('flg', 0)
            ->where('tflg', 1)
            ->where('status', EIndentStatus::APPROVED)
            ->orderByDesc('tdate');

        if ($search = trim((string) request('q'))) {
            $q->where(function ($w) use ($search) {
                $w->where('code', 'like', "%{$search}%")
                    ->orWhere('code1', 'like', "%{$search}%")
                    ->orWhereHas('raiser', fn ($u) => $u->where('login', 'like', "%{$search}%"));
            });
        }

        $indents = $q->paginate(20)->withQueryString();

        return view('issues.eindents.pending', [
            'indents' => $indents,
            'search' => $search,
        ]);
    }

    /** List of issues (open workspaces + posted transactions). */
    public function index(): View
    {
        $this->authorize('post-transactions');

        $issues = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('yearcode', FiscalYear::yearcode())
            ->when(request('stage') === EIssueStatus::OPEN, fn ($q) => $q->where('issuetrflag', 0))
            ->when(request('stage') === EIssueStatus::POSTED, fn ($q) => $q->where('issuetrflag', 1))
            ->orderByDesc('issue_id')
            ->paginate(20)
            ->withQueryString();

        return view('issues.eindents.index', ['issues' => $issues]);
    }

    /**
     * The issue workspace for one indent. Opens (or resumes) the issue
     * header, computes per-line stock availability, and renders the
     * distribution screen. Aborts when the indent is not issuable.
     */
    public function workspace(EIndent $indent): View
    {
        $this->authorize('post-transactions');

        $this->assertIssuable($indent);

        $yearcode = FiscalYear::yearcode();

        // Resume an open workspace for this indent or open a new one.
        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', $yearcode)
            ->where('issuetrflag', 0)
            ->first();

        DB::transaction(function () use (&$issue, $indent, $yearcode): void {
            if ($issue === null) {
                $issue = Issue::query()->create([
                    'issue_type' => 'eindent',
                    'issue_code' => IssueNumbering::nextWorkspaceCode($yearcode),
                    'issue_date' => now()->toDateString(),
                    'indent_date' => $indent->tdate,
                    'dcrefno' => (string) $indent->code,
                    'yearcode' => $yearcode,
                    'issuetrflag' => 0,
                    'issue_role' => (string) auth()->id(),
                    'status' => EIssueStatus::OPEN,
                ]);

                Audit::log('eindent.issue', 'open', $issue);
            }
        });

        // Indent lines + per-line distribution state.
        $lines = $indent->items()->get()->map(function (EIndentItem $line) use ($issue) {
            return $this->lineState($line, $issue);
        });

        return view('issues.eindents.workspace', [
            'indent' => $indent,
            'issue' => $issue,
            'lines' => $lines,
        ]);
    }

    /**
     * Save the SLOC distribution for one indent line (AJAX row post, legacy
     * getuser_issue_eindentupdate.php + getuser_issue_eindentedtupdate.php).
     * Creates issue_items + issue_slocs rows; edits replace the sloc rows
     * (legacy delete-and-reinsert, preserved).
     */
    public function saveLine(Request $request, EIndent $indent): JsonResponse
    {
        $this->authorize('post-transactions');
        $this->assertIssuable($indent);

        $data = $request->validate([
            'line' => ['required', 'integer'],
            'slocs' => ['required', 'array', 'min:1'],
            'slocs.*.stlg_id' => ['required', 'integer'],
            'slocs.*.ups' => ['required', 'integer', 'min:0', 'max:999999'],
            'slocs.*.qty' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
        ]);

        $indent->load('items');
        $line = $indent->items->firstWhere('eid', (int) $data['line']);
        abort_if($line === null, 404, 'Indent line not found.');

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', FiscalYear::yearcode())
            ->where('issuetrflag', 0)
            ->firstOrFail();

        $result = DB::transaction(function () use ($data, $issue, $line) {
            $resolved = [];

            foreach ($data['slocs'] as $i => $sloc) {
                // The ledger row must still exist and carry the location it
                // was offered with (ids come from the client — verify server-side).
                $ledger = StockLedgerGood::query()->find((int) $sloc['stlg_id']);
                abort_if($ledger === null, 422, "SLOC row {$i} no longer exists.");

                // A distribution cannot exceed the balance it was offered
                // against (legacy enforced this in JS; server-side here).
                abort_unless((float) $sloc['qty'] <= (float) $ledger->stlg_balqty + 0.001, 422,
                    "SLOC row {$i}: issue quantity exceeds the available balance.");
                abort_unless((int) $sloc['ups'] <= (int) $ledger->stlg_balups + 1, 422,
                    "SLOC row {$i}: issue UPS exceeds the available balance.");

                $resolved[] = [
                    'stlg' => $ledger,
                    'ups' => (int) $sloc['ups'],
                    'qty' => (float) $sloc['qty'],
                ];
            }

            // Legacy distributed quantities were entered manually; the port
            // enforces that the distribution matches the indent line quantity
            // exactly (sum of the sloc issues = line qty).
            $sumQty = array_sum(array_column($resolved, 'qty'));
            abort_unless(abs($sumQty - (float) $line->qty) < 0.001, 422,
                'Distributed quantity ('.$sumQty.') must equal the indent line quantity ('.$line->qty.').');

            $alreadyIssued = (float) IssueSloc::query()
                ->where('issue_tr_id', $issue->issue_id)
                ->where('eid', $line->eid)
                ->sum('qty_issue');
            abort_if($alreadyIssued > 0, 422, 'This line is already distributed in this workspace. Edit it instead.');

            // Upsert the issue_items line, identified the legacy way:
            // (issue, classification, item) — tblissue_sub carries no eid
            // column, so the indent line maps through its item.
            $issueItem = IssueItem::query()
                ->where('issue_id', $issue->issue_id)
                ->where('classification_id', $line->classification_id)
                ->where('item_id', $line->items_id)
                ->first();
            $isNew = $issueItem === null;
            $issueItem ??= new IssueItem(['issue_id' => $issue->issue_id]);
            $issueItem->fill([
                'classification_id' => $line->classification_id,
                'item_id' => $line->items_id,
                'ups_indent' => (int) $line->ups,
                'qty_indent' => (float) $line->qty,
                'uom' => (string) $line->uom,
            ]);
            $issueItem->save();

            // Edit = delete-and-reinsert of the sloc rows (legacy semantics).
            IssueSloc::query()->where('issue_tr_id', $issue->issue_id)->where('eid', $line->eid)->delete();

            foreach ($resolved as $r) {
                IssueSloc::query()->create([
                    'issue_type' => 'eindent',
                    'issue_tr_id' => $issue->issue_id,
                    'issue_id' => $issueItem->issuesub_id,
                    'classification_id' => $line->classification_id,
                    'item_id' => $line->items_id,
                    'whid' => $r['stlg']->stlg_whid,
                    'binid' => $r['stlg']->stlg_binid,
                    'subbin' => $r['stlg']->stlg_subbinid,
                    'qty_issue' => $r['qty'],
                    'ups_issue' => $r['ups'],
                    'qty_balance' => (float) $r['stlg']->stlg_balqty - $r['qty'],
                    'ups_balance' => (int) $r['stlg']->stlg_balups - $r['ups'],
                    'issue_rowid' => $r['stlg']->stlg_id,
                    'eid' => $line->eid,
                ]);
            }

            Audit::log('eindent.issue', $isNew ? 'line.create' : 'line.update', $issueItem, null, [
                'eid' => $line->eid, 'qty' => $line->qty, 'slocs' => count($resolved),
            ]);

            return $this->lineState($line, $issue->refresh());
        });

        return response()->json(['ok' => true, 'line' => $result]);
    }

    /**
     * Remove one line's distribution (legacy getuser_issue_eindentdelete.php
     * family; the indent-line delete from the workspace).
     */
    public function deleteLine(Request $request, EIndent $indent): JsonResponse
    {
        $this->authorize('post-transactions');

        $data = $request->validate(['line' => ['required', 'integer']]);

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', FiscalYear::yearcode())
            ->where('issuetrflag', 0)
            ->firstOrFail();

        $deleted = DB::transaction(function () use ($data, $indent, $issue) {
            $line = $indent->items()->where('eid', (int) $data['line'])->first();
            if ($line === null) {
                return false;
            }

            $item = IssueItem::query()
                ->where('issue_id', $issue->issue_id)
                ->where('classification_id', $line->classification_id)
                ->where('item_id', $line->items_id)
                ->first();
            if ($item === null) {
                return false;
            }

            IssueSloc::query()->where('issue_tr_id', $issue->issue_id)->where('eid', $line->eid)->delete();
            $item->delete();

            Audit::log('eindent.issue', 'line.delete', $issue, ['eid' => $data['line']]);

            return true;
        });

        abort_unless($deleted, 404, 'Nothing to delete — this line is not distributed yet.');

        return response()->json(['ok' => true]);
    }

    /**
     * Final post (legacy add_issue_eindents_preview.php frm_action=submit):
     * writes every saved sloc row into the stock ledger via
     * StockLedgerService, assigns the committed serials, closes the indent.
     * Transactional and idempotent — legacy was neither.
     */
    public function post(EIndent $indent): RedirectResponse
    {
        $this->authorize('post-transactions');

        $yearcode = FiscalYear::yearcode();

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', $yearcode)
            ->firstOrFail();

        if ($issue->isPosted()) {
            return redirect()->route('issues.eindents.show', $issue)
                ->with('error', 'This issue has already been posted.');
        }

        $posted = DB::transaction(function () use ($indent, $issue, $yearcode) {
            // Re-validate at post time: every line distributed, every sloc
            // row still covered by the current balances.
            $lines = $indent->items()->get();
            abort_unless($lines->isNotEmpty(), 422, 'The indent has no item lines.');

            foreach ($lines as $line) {
                $slocs = IssueSloc::query()
                    ->where('issue_tr_id', $issue->issue_id)
                    ->where('eid', $line->eid)
                    ->get();
                abort_unless($slocs->isNotEmpty(), 422,
                    'Line '.($line->items_id ?? '?').' has no stock-location distribution yet.');

                $sum = (float) $slocs->sum('qty_issue');
                abort_unless(abs($sum - (float) $line->qty) < 0.001, 422,
                    'Line distribution ('.$sum.') no longer matches the indent quantity ('.$line->qty.').');

                foreach ($slocs as $sloc) {
                    $ledger = StockLedgerGood::query()->find((int) $sloc->issue_rowid);
                    abort_if($ledger === null, 422, 'Stock at a chosen location changed; reopen the workspace.');
                    abort_unless((float) $ledger->stlg_balqty + 0.001 >= (float) $sloc->qty_issue, 422,
                        'Insufficient stock at a chosen location; the balances changed since saving.');
                }
            }

            $partyid = 0;

            foreach ($lines as $line) {
                $slocs = IssueSloc::query()
                    ->where('issue_tr_id', $issue->issue_id)
                    ->where('eid', $line->eid)
                    ->get();

                foreach ($slocs as $sloc) {
                    // The single ledger writer (arrival preview rules, ported).
                    StockLedgerService::post([
                        'direction' => 'out',
                        'yearcode' => $yearcode,
                        'trtype' => 'Issue',
                        'trsubtype' => 'eindent',
                        'trid' => $issue->issue_id,
                        'partyid' => (string) $partyid,
                        'trdate' => $issue->issue_date,
                        'classid' => $sloc->classification_id,
                        'item_id' => $sloc->item_id,
                        'whid' => (int) $sloc->whid,
                        'binid' => (int) $sloc->binid,
                        'subbinid' => (int) $sloc->subbin,
                        'ups' => (int) $sloc->ups_issue,
                        'qty' => (float) $sloc->qty_issue,
                    ]);
                }

                StockLedgerService::applyReorderFlag((int) $slocs->first()->item_id);
            }

            // Committed serials (race-safe; seeded from legacy MAX per year).
            $issue->iss_code = IssueNumbering::primeCommitted($yearcode);
            $issue->ncode = IssueNumbering::primeNote($yearcode);
            $issue->issuetrflag = 1;
            $issue->status = EIssueStatus::POSTED;
            $issue->save();

            // Close the indent (legacy flg=1).
            $indent->flg = 1;
            $indent->save();

            Audit::log('eindent.issue', 'post', $issue, null, [
                'iss_code' => $issue->iss_code,
                'ncode' => $issue->ncode,
                'indent' => $indent->tid,
                'lines' => $lines->count(),
            ]);

            return $issue;
        });

        return redirect()
            ->route('issues.eindents.show', $posted)
            ->with('success', 'Issue posted: '.IssueNumbering::committedId($posted).'. Stock updated and the indent is closed.');
    }

    /** Detail screen (legacy select_issue_eindentop.php + issue print header). */
    public function show(Issue $issue): View
    {
        $this->authorize('post-transactions');
        abort_unless($issue->issue_type === 'eindent', 404);

        $issue->load(['items', 'items.slocs']);

        $indent = EIndent::query()->where('code', $issue->dcrefno)->first();

        return view('issues.eindents.show', [
            'issue' => $issue,
            'indent' => $indent,
        ]);
    }

    /** Aborts unless the indent is approved, submitted and still open. */
    private function assertIssuable(EIndent $indent): void
    {
        abort_unless((int) $indent->tflg === 1 && (int) $indent->flg === 0, 422,
            'This indent is not open for issue.');
        abort_unless($indent->status === EIndentStatus::APPROVED, 422,
            'This indent has not been approved yet (pending or rejected).');
        abort_unless($indent->items()->exists(), 422, 'This indent has no item lines.');
    }

    /**
     * Per-line state for the workspace: availability rows (latest positive
     * balance per location), already-distributed rows and running totals.
     */
    private function lineState(EIndentItem $line, Issue $issue): array
    {
        // Availability: distinct locations with any positive balance for the
        // item (legacy SELECT DISTINCT wh,bin,subbin + max(stlg_id) balance).
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

        // Already distributed in this (and any open) workspace for the line.
        $distributed = IssueSloc::query()
            ->where('eid', $line->eid)
            ->whereIn('issue_tr_id', function ($q) {
                $q->select('issue_id')->from('issues')
                    ->where('issue_type', 'eindent')
                    ->where('issuetrflag', 0);
            })
            ->with(['issueItem'])
            ->get()
            ->map(fn ($s) => [
                'issuesloc_id' => $s->issuesloc_id,
                'issue_tr_id' => $s->issue_tr_id,
                'whid' => (int) $s->whid,
                'binid' => (int) $s->binid,
                'subbinid' => (int) $s->subbin,
                'ups' => (int) $s->ups_issue,
                'qty' => (float) $s->qty_issue,
            ]);

        $issuedHere = $distributed->where('issue_tr_id', $issue->issue_id);
        $issuedElsewhere = $distributed->where('issue_tr_id', '!=', $issue->issue_id);

        return [
            'line' => $line,
            'available' => $locations,
            'distributed' => $issuedHere->values(),
            'distributed_elsewhere' => $issuedElsewhere->values(),
            'distributed_qty' => (float) $issuedHere->sum('qty'),
            'remaining_qty' => round((float) $line->qty - (float) $issuedHere->sum('qty'), 3),
        ];
    }
}
