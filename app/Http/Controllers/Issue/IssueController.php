<?php

namespace App\Http\Controllers\Issue;

use App\Http\Controllers\Controller;
use App\Models\Classification;
use App\Models\Issue;
use App\Models\IssueItem;
use App\Models\IssueSloc;
use App\Models\Item;
use App\Models\Party;
use App\Models\StockLedgerGood;
use App\Support\Audit;
use App\Support\EIssueStatus;
use App\Support\FiscalYear;
use App\Support\IssueNumbering;
use App\Support\IssueTypes;
use App\Support\StockLedgerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The three self-contained issue types sharing the legacy tblissue skeleton:
 * physical indent (pindent), stock transfer (stocktr) and material return to
 * vendor (mrtv). Reuses the Phase-6 e-issue posting skeleton:
 *
 *  1. The header (issues row, issuetrflag=0) is created with the first line
 *     post — legacy created it there too (getuser_issue_*update.php inserts
 *     the header when trid=0). issue_code = MAX(issue_code)+1 per yearcode x
 *     type, lock-guarded (legacy's MAX+1 race fixed).
 *  2. Per line: item/classification/qty/uom plus the SLOC distribution
 *     (issue_items + issue_slocs rows). Distribution sums must equal the
 *     line quantity and each row is checked against the live ledger balance
 *     at save time (legacy checked in JS only) and again at post time.
 *  3. Final post (legacy add_issue_*_preview.php frm_action=submit): every
 *     sloc row is written through StockLedgerService (direction out, trtype
 *     'Issue', trsubtype = issue_type), the committed serials iss_code/ncode
 *     are assigned from per-type race-safe counters, issuetrflag=1. The post
 *     is transactional and idempotent (legacy was neither — it could go
 *     negative or double-post).
 *
 * Port deltas (deliberate, documented): server-side balance and coverage
 * validation; header-field validation per type (transport/courier/by-hand
 * conditional fields); line quantities are editable (the legacy row-update
 * handler wrote qty=qty so quantities could never change).
 */
class IssueController extends Controller
{
    use AuthorizesRequests;

    public function index(string $type): View
    {
        $this->authorize('post-transactions');
        abort_unless(IssueTypes::META[$type] ?? null, 404);

        $issues = Issue::query()
            ->where('issue_type', $type)
            ->where('yearcode', FiscalYear::yearcode())
            ->when(request('stage') === EIssueStatus::OPEN, fn ($q) => $q->where('issuetrflag', 0))
            ->when(request('stage') === EIssueStatus::POSTED, fn ($q) => $q->where('issuetrflag', 1))
            ->orderByDesc('issue_id')
            ->paginate(20)
            ->withQueryString();

        return view('issues.types.index', [
            'type' => $type,
            'meta' => IssueTypes::META[$type],
            'issues' => $issues,
        ]);
    }

    /** Entry screen: header fields for the type + the first item line. */
    public function create(string $type): View
    {
        $this->authorize('post-transactions');
        abort_unless(IssueTypes::META[$type] ?? null, 404);

        return view('issues.types.create', [
            'type' => $type,
            'meta' => IssueTypes::META[$type],
            'classifications' => Classification::query()->orderBy('classification')->get(['classification_id', 'classification']),
            'parties' => $type === 'pindent'
                ? collect()
                : Party::query()->orderBy('business_name')->get(['p_id', 'business_name']),
        ]);
    }

    /**
     * Add a line (AJAX, legacy getuser_issue_{pindent,str,mrtv}update.php).
     * With no issue_id the header is created first (legacy trid=0 branch).
     */
    public function storeLine(Request $request, string $type): JsonResponse
    {
        $this->authorize('post-transactions');
        abort_unless(IssueTypes::META[$type] ?? null, 404);

        $data = $this->validateLine($request, $type);

        $issue = isset($data['issue_id'])
            ? $this->findOpenIssue((int) $data['issue_id'], $type)
            : null;

        $result = DB::transaction(function () use ($data, $type, &$issue) {
            $issue ??= $this->createHeader($type, $data);

            $line = $this->saveLine($issue, $data);

            Audit::log("issue.{$type}", 'line.create', $line, null, [
                'item' => $data['items_id'], 'qty' => $data['qty'], 'slocs' => count($data['slocs']),
            ]);

            return $this->lineState($line, $issue);
        });

        return response()->json([
            'ok' => true,
            'issue_id' => $issue->issue_id,
            'redirect' => route("issues.{$type}.workspace", $issue),
            'line' => $result,
        ]);
    }

    /**
     * Replace one line and its distribution (legacy edtupdate handler,
     * which deleted and re-inserted). Fixes the legacy qty=qty bug: the
     * line quantity is actually updatable here.
     */
    public function updateLine(Request $request, IssueItem $line): JsonResponse
    {
        $this->authorize('post-transactions');

        // tblissue_sub carries no issue_type column — resolve via the header.
        $issue = $this->findOpenIssue((int) $line->issue_id);
        $type = $issue->issue_type;
        abort_unless(IssueTypes::META[$type] ?? null, 404);

        $data = $this->validateLine($request, $type);

        $result = DB::transaction(function () use ($issue, $line, $data) {
            IssueSloc::query()
                ->where('issue_tr_id', $issue->issue_id)
                ->where('issue_id', $line->issuesub_id)
                ->delete();

            $updated = $this->saveLine($issue, $data, $line);

            Audit::log("issue.{$issue->issue_type}", 'line.update', $updated, null, [
                'item' => $data['items_id'], 'qty' => $data['qty'],
            ]);

            return $this->lineState($updated, $issue);
        });

        return response()->json(['ok' => true, 'line' => $result]);
    }

    /** Remove one line + its distribution (legacy getuser_issue_*delete.php). */
    public function deleteLine(IssueItem $line): JsonResponse
    {
        $this->authorize('post-transactions');

        // tblissue_sub carries no issue_type column — resolve via the header.
        $issue = $this->findOpenIssue((int) $line->issue_id);
        $type = $issue->issue_type;
        abort_unless(IssueTypes::META[$type] ?? null, 404);

        $deleted = DB::transaction(function () use ($issue, $line) {
            IssueSloc::query()
                ->where('issue_tr_id', $issue->issue_id)
                ->where('issue_id', $line->issuesub_id)
                ->delete();

            Audit::log("issue.{$issue->issue_type}", 'line.delete', $issue, ['item' => $line->item_id]);

            return $line->delete();
        });

        abort_unless($deleted, 404, 'Nothing to delete.');

        return response()->json(['ok' => true]);
    }

    /** The workspace: existing lines, per-line distribution, add-line form. */
    public function workspace(Issue $issue): View
    {
        $this->authorize('post-transactions');
        $type = $issue->issue_type;
        abort_unless(IssueTypes::META[$type] ?? null, 404);

        $issue->load(['items.slocs']);

        $lines = $issue->items->map(fn (IssueItem $line) => $this->lineState($line, $issue));

        return view('issues.types.workspace', [
            'type' => $type,
            'meta' => IssueTypes::META[$type],
            'issue' => $issue,
            'lines' => $lines,
            'classifications' => Classification::query()->orderBy('classification')->get(['classification_id', 'classification']),
        ]);
    }

    /** Edit the header fields while open (legacy preview-screen update). */
    public function updateHeader(Request $request, Issue $issue): RedirectResponse
    {
        $this->authorize('post-transactions');
        $type = $issue->issue_type;
        abort_unless(IssueTypes::META[$type] ?? null, 404);
        abort_if($issue->isPosted(), 422, 'Posted issues are immutable.');

        $data = $request->validate($this->headerRules($type));

        $issue->fill($this->headerFields($type, $data) + ['remarks' => $data['remarks'] ?? $issue->remarks]);
        $issue->save();

        Audit::log("issue.{$type}", 'header.update', $issue);

        return back()->with('success', 'Header updated.');
    }

    /**
     * Final post (legacy add_issue_*_preview.php): writes every saved sloc
     * row through StockLedgerService, assigns the committed serials.
     * Transactional and idempotent.
     */
    public function post(Issue $issue): RedirectResponse
    {
        $this->authorize('post-transactions');
        $type = $issue->issue_type;
        abort_unless(IssueTypes::META[$type] ?? null, 404);

        if ($issue->isPosted()) {
            return redirect()->route("issues.{$type}.show", $issue)
                ->with('error', 'This issue has already been posted.');
        }

        $yearcode = FiscalYear::yearcode();

        $posted = DB::transaction(function () use ($issue, $yearcode) {
            $lines = $issue->items()->get();
            abort_unless($lines->isNotEmpty(), 422, 'This issue has no item lines.');

            $trtype = 'Issue';
            $partyid = (string) ($issue->party_id ?: 0);

            foreach ($lines as $line) {
                $slocs = IssueSloc::query()
                    ->where('issue_tr_id', $issue->issue_id)
                    ->where('issue_id', $line->issuesub_id)
                    ->get();
                abort_unless($slocs->isNotEmpty(), 422,
                    'Line for item '.$line->item_id.' has no stock-location distribution yet.');

                $sum = (float) $slocs->sum('qty_issue');
                abort_unless(abs($sum - (float) $line->qty_indent) < 0.001, 422,
                    'Line distribution ('.$sum.') no longer matches the line quantity ('.$line->qty_indent.').');

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
                        'trtype' => $trtype,
                        'trsubtype' => $issue->issue_type,
                        'trid' => $issue->issue_id,
                        'partyid' => $partyid,
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

                StockLedgerService::applyReorderFlag((int) $line->item_id);
            }

            $issue->iss_code = IssueNumbering::primeCommitted($yearcode, $issue->issue_type);
            $issue->ncode = IssueNumbering::primeNote($yearcode, $issue->issue_type);
            $issue->issuetrflag = 1;
            $issue->status = EIssueStatus::POSTED;
            $issue->save();

            Audit::log("issue.{$issue->issue_type}", 'post', $issue, null, [
                'iss_code' => $issue->iss_code,
                'ncode' => $issue->ncode,
                'lines' => $lines->count(),
            ]);

            return $issue;
        });

        return redirect()
            ->route("issues.{$type}.show", $posted)
            ->with('success', 'Issue posted: '.IssueNumbering::committedId($posted).'. Stock updated.');
    }

    /** Detail screen (legacy select_issue_*op.php / *_view.php). */
    public function show(Issue $issue): View
    {
        $this->authorize('post-transactions');
        $type = $issue->issue_type;
        abort_unless(IssueTypes::META[$type] ?? null, 404);

        $issue->load(['items.slocs']);
        $party = $issue->party_id ? Party::query()->find($issue->party_id) : null;

        return view('issues.types.show', [
            'type' => $type,
            'meta' => IssueTypes::META[$type],
            'issue' => $issue,
            'party' => $party,
        ]);
    }

    // ------------------------------------------------------------------

    /** Validation for a line post, including per-type header fields. */
    private function validateLine(Request $request, string $type): array
    {
        $data = $request->validate($this->headerRules($type) + [
            'issue_id' => ['nullable', 'integer'],
            'classification_id' => ['required', 'integer'],
            'items_id' => ['required', 'integer'],
            'ups' => ['required', 'integer', 'min:0', 'max:999999'],
            'qty' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'uom' => ['required', 'string', 'max:50'],
            'slocs' => ['required', 'array', 'min:1'],
            'slocs.*.stlg_id' => ['required', 'integer'],
            'slocs.*.ups' => ['required', 'integer', 'min:0', 'max:999999'],
            'slocs.*.qty' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
        ]);

        // The item must exist, be active and belong to the classification
        // (legacy trusted the client chain; the port verifies server-side).
        $item = Item::query()->find((int) $data['items_id']);
        abort_if($item === null, 422, 'Item not found.');
        abort_unless((int) $item->classification_id === (int) $data['classification_id'], 422,
            'The selected item does not belong to the selected classification.');
        abort_unless($item->actstatus === 'Active', 422, 'The item is inactive.');

        // Distribution must cover the line quantity exactly and each row
        // must fit within the live ledger balance it was offered against.
        $sumQty = 0.0;
        foreach ($data['slocs'] as $i => $sloc) {
            $ledger = StockLedgerGood::query()->find((int) $sloc['stlg_id']);
            abort_if($ledger === null, 422, "SLOC row {$i} no longer exists.");
            abort_if((int) $ledger->stlg_tritemid !== (int) $data['items_id'], 422,
                "SLOC row {$i} belongs to a different item.");
            abort_unless((float) $sloc['qty'] <= (float) $ledger->stlg_balqty + 0.001, 422,
                "SLOC row {$i}: issue quantity exceeds the available balance.");
            abort_unless((int) $sloc['ups'] <= (int) $ledger->stlg_balups + 1, 422,
                "SLOC row {$i}: issue UPS exceeds the available balance.");

            $sumQty += (float) $sloc['qty'];
        }
        abort_unless(abs($sumQty - (float) $data['qty']) < 0.001, 422,
            'Distributed quantity ('.$sumQty.') must equal the line quantity ('.$data['qty'].').');

        return $data;
    }

    /** Per-type header validation (legacy conditional transport fields). */
    private function headerRules(string $type): array
    {
        $modeFields = [
            'tmode' => ['required', 'in:Transport,Courier,By Hand'],
            'trans_name' => ['nullable', 'string', 'max:100'],
            'trans_lorryrepno' => ['nullable', 'string', 'max:50'],
            'trans_vehno' => ['nullable', 'string', 'max:50'],
            'trans_paymode' => ['nullable', 'string', 'max:50'],
            'courier_name' => ['nullable', 'string', 'max:100'],
            'docket_no' => ['nullable', 'string', 'max:50'],
            'pname_byhand' => ['nullable', 'string', 'max:250'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];

        return match ($type) {
            IssueTypes::PHYSICAL_INDENT => [
                'dcrefno' => ['required', 'string', 'max:50'],   // legacy txtindent: physical indent number
                'strefno' => ['required', 'string', 'max:50'],   // legacy txtphysical: raised by
                'issue_date' => ['required', 'date'],
            ] + $modeFields,
            IssueTypes::STOCK_TRANSFER => [
                'strefno' => ['required', 'string', 'max:50'],   // legacy txtstrno: transfer ref
                'strdate' => ['required', 'date'],
                'party_id' => ['required', 'integer', 'exists:parties,p_id'],
                'rettyp' => ['required', 'string', 'max:50'],
            ] + $modeFields,
            IssueTypes::MRTV => [
                'party_id' => ['required', 'integer', 'exists:parties,p_id'],
                'dcrefno' => ['required', 'string', 'max:50'],   // legacy txtcla->dc: party DC ref
                'issue_date' => ['required', 'date'],
            ] + $modeFields,
            default => [],
        };
    }

    /** Map validated header fields onto the issues columns (legacy names). */
    private function headerFields(string $type, array $data): array
    {
        $fields = [
            'tmode' => $data['tmode'] ?? null,
            'trans_name' => $data['trans_name'] ?? null,
            'trans_lorryrepno' => $data['trans_lorryrepno'] ?? null,
            'trans_vehno' => $data['trans_vehno'] ?? null,
            'trans_paymode' => $data['trans_paymode'] ?? null,
            'courier_name' => $data['courier_name'] ?? null,
            'docket_no' => $data['docket_no'] ?? null,
            'pname_byhand' => $data['pname_byhand'] ?? null,
        ];

        return match ($type) {
            IssueTypes::PHYSICAL_INDENT => [
                'dcrefno' => $data['dcrefno'],
                'strefno' => $data['strefno'],
                'issue_date' => $data['issue_date'],
            ] + $fields,
            IssueTypes::STOCK_TRANSFER => [
                'strefno' => $data['strefno'],
                'strdate' => $data['strdate'],
                'party_id' => (int) $data['party_id'],
                'rettyp' => $data['rettyp'],
                'issue_date' => $data['strdate'],
            ] + $fields,
            IssueTypes::MRTV => [
                'party_id' => (int) $data['party_id'],
                'dcrefno' => $data['dcrefno'],
                'issue_date' => $data['issue_date'],
            ] + $fields,
            default => [],
        };
    }

    /** Find this type's open workspace (legacy keyed lookups, typed). */
    private function findOpenIssue(int $issueId, ?string $type = null): Issue
    {
        $issue = Issue::query()
            ->where('issue_id', $issueId)
            ->where('yearcode', FiscalYear::yearcode())
            ->first();

        abort_if($issue === null, 404, 'Issue workspace not found.');
        abort_if($type !== null && $issue->issue_type !== $type, 404, 'Issue workspace not found.');
        abort_if($issue->isPosted(), 422, 'This issue has already been posted and is immutable.');

        return $issue;
    }

    /** Create the header on first line post (legacy trid=0 branch). */
    private function createHeader(string $type, array $header): Issue
    {
        $yearcode = FiscalYear::yearcode();

        $issue = Issue::query()->create($this->headerFields($type, $header) + [
            'issue_type' => $type,
            'issue_code' => IssueNumbering::nextWorkspaceCode($yearcode, $type),
            'yearcode' => $yearcode,
            'issuetrflag' => 0,
            'issue_role' => (string) auth()->id(),
            'status' => EIssueStatus::OPEN,
            'remarks' => $header['remarks'] ?? null,
        ]);

        Audit::log("issue.{$type}", 'open', $issue);

        return $issue;
    }

    /**
     * Persist one line + its sloc rows (create or replace). $line is passed
     * for the replace path (legacy delete-and-reinsert of the slocs only).
     */
    private function saveLine(Issue $issue, array $data, ?IssueItem $line = null): IssueItem
    {
        $isNew = $line === null;
        $line ??= new IssueItem(['issue_id' => $issue->issue_id]);

        $line->fill([
            'classification_id' => (int) $data['classification_id'],
            'item_id' => (int) $data['items_id'],
            'ups_indent' => (int) $data['ups'],
            'qty_indent' => (float) $data['qty'],
            'uom' => (string) $data['uom'],
        ]);
        $line->save();

        foreach ($data['slocs'] as $sloc) {
            $ledger = StockLedgerGood::query()->find((int) $sloc['stlg_id']);

            IssueSloc::query()->create([
                'issue_type' => $issue->issue_type,
                'issue_tr_id' => $issue->issue_id,
                'issue_id' => $line->issuesub_id,
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
                'eid' => $line->issuesub_id,
            ]);
        }

        return $line;
    }

    /**
     * Per-line state for the workspace: availability rows (latest positive
     * balance per location) and the saved distribution (legacy preview
     * queried tblissue_sloc by issue_tr_id + classification + item).
     */
    private function lineState(IssueItem $line, Issue $issue): array
    {
        $locations = StockLedgerGood::query()
            ->select('stlg_whid', 'stlg_binid', 'stlg_subbinid')
            ->where('stlg_tritemid', $line->item_id)
            ->where('stlg_trclassid', $line->classification_id)
            ->groupBy('stlg_whid', 'stlg_binid', 'stlg_subbinid')
            ->orderBy('stlg_whid')
            ->orderBy('stlg_binid')
            ->orderBy('stlg_subbinid')
            ->get()
            ->map(function ($loc) use ($line) {
                $latest = StockLedgerGood::query()
                    ->where('stlg_tritemid', $line->item_id)
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

        $distributed = IssueSloc::query()
            ->where('issue_tr_id', $issue->issue_id)
            ->where('issue_id', $line->issuesub_id)
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
