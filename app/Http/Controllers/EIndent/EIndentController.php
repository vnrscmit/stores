<?php

namespace App\Http\Controllers\EIndent;

use App\Http\Controllers\Controller;
use App\Http\Requests\EIndent\RaiseIndentRequest;
use App\Models\EIndent;
use App\Support\Audit;
use App\Support\EIndentStatus;
use App\Support\FiscalYear;
use App\Support\IndentNumbering;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * e-Indent raise module (legacy add_indents.php workspace,
 * home_pending _indents.php listing, add_indents_view.php detail,
 * add_indents_preview.php final submit).
 *
 * Lifecycle ported on the legacy flags (tflg, flg) plus an approval gate:
 *   draft -> pending (submit) -> approved | rejected -> (rejected: back to draft).
 * Approving a rejected indent re-consumes the original committed code.
 * Legacy hardcoded the "3 indents" raise limit out (commented); the port
 * enforces a per-day cap of 3 open (pending/approved) indents.
 */
class EIndentController extends Controller
{
    private const DAILY_OPEN_LIMIT = 3;

    /** GET /eindents — my indents (legacy home_pending _indents.php). */
    public function index(): View
    {
        $q = trim((string) request('q'));
        $status = (string) request('status', '');

        $indents = EIndent::query()
            ->where('id', auth()->id())
            ->where('yearcode', FiscalYear::yearcode())
            ->when($status !== '' && in_array($status, EIndentStatus::FILTERS, true), fn ($query) => $query->where('status', $status))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', $q)
                        ->orWhere('code1', $q)
                        ->orWhere('remarks', 'like', "%{$q}%");
                });
            })
            ->withCount('items')
            ->orderByDesc('tid')
            ->paginate(10)
            ->withQueryString();

        return view('eindents.index', [
            'indents' => $indents,
            'q' => $q,
            'status' => $status,
            'canRaiseMore' => self::canRaiseMore((int) auth()->id()),
        ]);
    }

    /** GET /eindents/raise — draft workspace (legacy add_indents.php). */
    public function raise(): View
    {
        abort_unless(self::canRaiseMore((int) auth()->id()), 403,
            'You already have '.self::DAILY_OPEN_LIMIT.' open indents today. Wait for approval or issue.');

        return view('eindents.raise', [
            'indent' => null,
            'classifications' => $this->classificationOptions(),
        ]);
    }

    /** GET /eindents/{indent}/workspace — continue editing a draft. */
    public function workspace(EIndent $indent): View
    {
        abort_unless((int) $indent->id === auth()->id(), 403, 'You can only edit your own indents.');
        abort_unless($indent->tflg == 0, 422, 'Submitted indents can no longer be edited.');

        return view('eindents.raise', [
            'indent' => $indent->load('items'),
            'classifications' => $this->classificationOptions(),
        ]);
    }

    /** GET /eindents/{indent} — detail / preview (legacy add_indents_view.php). */
    public function show(EIndent $indent): View
    {
        $isOwner = (int) $indent->id === auth()->id();

        abort_unless($isOwner || auth()->user()->can('post-transactions') || auth()->user()->can('manage-masters'),
            403, 'You cannot view this indent.');

        return view('eindents.show', [
            'indent' => $indent->load(['items.item', 'items.classification', 'raiser']),
            'transactionId' => IndentNumbering::transactionId($indent),
            'isOwner' => $isOwner,
        ]);
    }

    /** PUT /eindents/{indent}/remarks — legacy remarks autosave. */
    public function updateRemarks(RaiseIndentRequest $request, EIndent $indent): RedirectResponse
    {
        abort_unless((int) $indent->id === auth()->id(), 403, 'You can only edit your own indents.');
        abort_unless($indent->tflg == 0, 422, 'Submitted indents can no longer be edited.');

        $indent->update(['remarks' => $request->input('remarks')]);

        return back()->with('status', 'Remarks saved.');
    }

    /** GET /eindents-approvals — admin approval queue (port extension). */
    public function approvals(): View
    {
        $q = trim((string) request('q'));
        $status = (string) request('status', EIndentStatus::PENDING);

        $indents = EIndent::query()
            ->where('yearcode', FiscalYear::yearcode())
            ->when($status !== '' && in_array($status, EIndentStatus::FILTERS, true), fn ($query) => $query->where('status', $status))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', $q)
                        ->orWhere('code1', $q)
                        ->orWhere('remarks', 'like', "%{$q}%");
                });
            })
            ->with(['raiser', 'items.item'])
            ->withCount('items')
            ->orderByDesc('tid')
            ->paginate(15)
            ->withQueryString();

        return view('eindents.approvals', [
            'indents' => $indents,
            'q' => $q,
            'status' => $status,
        ]);
    }

    /**
     * POST /eindents/{indent}/submit — legacy final submit
     * (add_indents_preview.php: assign code, tflg=1). Port adds the approval
     * gate: submitted indents land in `pending` for admin approval.
     */
    public function submit(EIndent $indent): RedirectResponse
    {
        abort_unless((int) $indent->id === auth()->id(), 403, 'You can only submit your own indents.');
        abort_unless($indent->tflg == 0, 422, 'This indent is already submitted.');
        abort_unless($indent->items()->exists(), 422, 'You have not posted any item. Please post & then click Preview.');

        $committed = DB::transaction(function () use ($indent): EIndent {
            $indent->code = $indent->code ?? IndentNumbering::nextCommittedCode(FiscalYear::yearcode());
            $indent->tflg = 1;
            $indent->status = EIndentStatus::PENDING;
            $indent->save();

            return $indent;
        });

        Audit::log('eindent', 'submit', $committed, ['tflg' => 0],
            ['tflg' => 1, 'code' => $committed->code, 'status' => EIndentStatus::PENDING]);

        return redirect()->route('eindents.index')->with('status',
            'Indent '.IndentNumbering::committedId($committed).' submitted for approval.');
    }

    /** POST /eindents/{indent}/approve — admin approval gate. */
    public function approve(EIndent $indent): RedirectResponse
    {
        $this->authorizeDecision($indent);

        $before = $indent->only(['status']);
        $indent->status = EIndentStatus::APPROVED;
        $indent->save();

        Audit::log('eindent', 'approve', $indent, $before, ['status' => EIndentStatus::APPROVED]);

        return back()->with('status', 'Indent '.IndentNumbering::committedId($indent).' approved and open for issue.');
    }

    /** POST /eindents/{indent}/reject — admin returns it to the raiser. */
    public function reject(EIndent $indent): RedirectResponse
    {
        $this->authorizeDecision($indent);

        $before = $indent->only(['status']);
        $indent->status = EIndentStatus::REJECTED;
        $indent->save();

        Audit::log('eindent', 'reject', $indent, $before, ['status' => EIndentStatus::REJECTED]);

        return back()->with('status', 'Indent '.IndentNumbering::committedId($indent).' rejected and returned to the raiser.');
    }

    /** POST /eindents/{indent}/reopen — raiser pulls a rejected indent back to draft. */
    public function reopen(EIndent $indent): RedirectResponse
    {
        abort_unless((int) $indent->id === auth()->id(), 403, 'You can only edit your own indents.');
        abort_unless($indent->status === EIndentStatus::REJECTED, 422, 'Only rejected indents can be reopened.');

        $before = $indent->only(['status', 'tflg']);
        $indent->status = EIndentStatus::DRAFT;
        $indent->tflg = 0;
        $indent->save();

        Audit::log('eindent', 'reopen', $indent, $before, ['status' => EIndentStatus::DRAFT, 'tflg' => 0]);

        return redirect()->route('eindents.workspace', $indent)->with('status', 'Indent reopened for editing.');
    }

    private function authorizeDecision(EIndent $indent): void
    {
        abort_unless(auth()->user()->can('manage-masters'), 403, 'Only administrators can approve or reject indents.');
        abort_unless($indent->status === EIndentStatus::PENDING, 422, 'Only pending indents can be approved or rejected.');
    }

    /** Legacy "up to 3 indents" guard: per raiser, open pipeline only. */
    public static function canRaiseMore(int $userId): bool
    {
        return EIndent::query()
            ->where('id', $userId)
            ->where('yearcode', FiscalYear::yearcode())
            ->whereIn('status', [EIndentStatus::PENDING, EIndentStatus::APPROVED])
            ->count() < self::DAILY_OPEN_LIMIT;
    }

    private function classificationOptions()
    {
        return DB::table('classifications')->orderBy('classification')
            ->pluck('classification', 'classification_id');
    }
}
