<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinancialYear;
use App\Support\Audit;
use App\Support\FiscalYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Year Setting — the port of Masters/current_year.php (activate a year)
 * and Masters/closeyear.php (close the active year and open its
 * successor), over the legacy tblyears vocabulary:
 *
 *  - flg 2 + status 'a' = the chosen current year (current_year.php);
 *  - flg 1 + status 'a' = the year opened by a close (closeyear.php);
 *  - flg 0 + status 'c' = a closed year (closeyear.php).
 *
 * Legacy current_year.php also created a per-year expro* database and
 * seeded it from a dump; that belongs to the multi-database deployment
 * the single-database port replaces, so only the tblyears state machine
 * is ported. closeyear.php picked the successor as yearsid + 1 (the
 * next row in insertion order) — preserved.
 *
 * Concurrency: the whole switch is one transaction with the year rows
 * locked, the session's cached resolution is flushed afterwards, and
 * every action is audited.
 */
class YearController extends Controller
{
    /** The year management screen. */
    public function index(): View
    {
        $years = FinancialYear::query()
            ->orderByDesc('yearsid')
            ->get();

        $active = FiscalYear::resolve();

        return view('admin.years', [
            'years' => $years,
            'active' => $active,
            // Legacy closeyear.php: the successor is yearsid + 1.
            'successor' => FinancialYear::query()->where('yearsid', $active->yearsid + 1)->first(),
        ]);
    }

    /**
     * Activate a year (legacy current_year.php: chosen -> flg=2,
     * status='a'; a previously open year -> flg=1).
     */
    public function activate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'yearsid' => ['required', 'integer'],
        ]);

        $target = FinancialYear::query()->findOrFail((int) $validated['yearsid']);
        abort_unless((string) $target->years_status !== 'c', 422, 'A closed year cannot be reactivated.');

        $switched = DB::transaction(function () use ($target) {
            $previous = FinancialYear::query()
                ->where('years_status', 'a')
                ->where('years_flg', '!=', 0)
                ->lockForUpdate()
                ->get();

            $previousWasCurrent = $previous->contains(fn ($y) => $y->yearsid === $target->yearsid);

            // A previously open year keeps flg 1 but loses its active
            // status (current_year.php wrote flg=1 without touching the
            // status — which would leave TWO status-'a' rows and make the
            // session contract ambiguous; the port demotes it to 'u' so
            // exactly one year is active, and documents stay untouched).
            $previous->each(fn (FinancialYear $y) => $y->update(['years_flg' => 1, 'years_status' => 'u']));

            $target->update(['years_flg' => 2, 'years_status' => 'a']);
            $target->refresh();

            return $previousWasCurrent;
        });

        FiscalYear::flush();

        Audit::log('admin.years', $switched ? 'reassert' : 'activate', $target, null, [
            'ycode' => $target->ycode,
        ]);

        return redirect()->route('admin.years.index')
            ->with('status', 'Active year set to '.$target->year_name.'.');
    }

    /**
     * Close the active year and open its successor (legacy closeyear.php:
     * closing -> flg=0, status='c'; successor yearsid+1 -> flg=1,
     * status='a').
     */
    public function close(): RedirectResponse
    {
        $closed = DB::transaction(function () {
            $active = FiscalYear::resolve();

            // Lock the year rows for the duration of the switch.
            FinancialYear::query()
                ->where('years_status', 'a')
                ->where('years_flg', '!=', 0)
                ->lockForUpdate()
                ->get();

            // Legacy closeyear.php: the successor is yearsid + 1 — the
            // next row in insertion order.
            $successor = FinancialYear::query()
                ->where('yearsid', $active->yearsid + 1)
                ->lockForUpdate()
                ->first();

            abort_if($successor === null, 422,
                'No successor year exists (the next yearsid row is missing). Create it before closing.');

            $active->update(['years_flg' => 0, 'years_status' => 'c']);
            $successor->update(['years_flg' => 1, 'years_status' => 'a']);

            return [$active, $successor];
        });

        [$closedYear, $successor] = $closed;

        FiscalYear::flush();

        Audit::log('admin.years', 'close', $closedYear, null, [
            'closed' => $closedYear->ycode,
            'opened' => $successor->ycode,
        ]);

        return redirect()->route('admin.years.index')
            ->with('status', 'Year '.$closedYear->year_name.' closed; '.$successor->year_name.' is now active.');
    }
}
