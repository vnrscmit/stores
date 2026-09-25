<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The audit trail screen (Phase 10 slice 2). There is no legacy screen
 * to mirror — the legacy "audit trail" (Transaction/audit_trail_debug
 * .php) was a hardcoded developer debug page over the QR scan tables
 * that never existed in the live database (see docs/PHASE10.md 2.5).
 * The port has logged every master change, approval and posting since
 * the first Phase 9 slice via App\Support\Audit; this screen makes that
 * trail readable, in the spirit of the legacy debug page (recent first,
 * counts by action) over the port's own data:
 *
 *  - newest first, paginated;
 *  - filters: module (exact, chosen from the modules actually present),
 *    action (exact), user login (LIKE), date range (inclusive);
 *  - a counts-by-action summary for the current filter, mirroring the
 *    legacy debug screen's section 2;
 *  - per-row before/after snapshots rendered as JSON.
 */
class AuditTrailController extends Controller
{
    use AuthorizesRequests;

    /** The trail listing (the legacy debug screen, productized). */
    public function index(Request $request): View
    {
        $this->authorize('viewAuditTrail');

        $module = trim((string) $request->query('module'));
        $action = trim((string) $request->query('action'));
        $userLogin = trim((string) $request->query('user'));
        $from = (string) $request->query('from');
        $to = (string) $request->query('to');

        $entries = AuditLog::query()
            ->when($module !== '', fn ($q) => $q->where('module', $module))
            ->when($action !== '', fn ($q) => $q->where('action', $action))
            ->when($userLogin !== '', fn ($q) => $q->where('user_login', 'like', "%{$userLogin}%"))
            ->when($from !== '', fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Counts by action under the current filter (legacy debug screen
        // section 2, minus its hardcoded action list).
        $byAction = (clone $entries->getCollection())->countBy('action')->sortDesc();

        return view('audit.index', [
            'entries' => $entries,
            'byAction' => $byAction,
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module'),
            'filters' => [
                'module' => $module,
                'action' => $action,
                'user' => $userLogin,
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }

    /** One entry with its full before/after snapshots. */
    public function show(AuditLog $audit): View
    {
        $this->authorize('viewAuditTrail');

        return view('audit.show', ['entry' => $audit]);
    }
}
