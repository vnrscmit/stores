<?php

namespace App\Support;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The substance behind the /up health route (registered as
 * `health: '/up'` in bootstrap/app.php and wired to this listener in
 * AppServiceProvider). The framework route dispatches DiagnosingHealth
 * and answers 200 only when every listener completes — a thrown
 * exception turns the probe into a 500 (reported to the log, with the
 * message included only while APP_DEBUG is on, so nothing sensitive
 * leaks through the monitoring endpoint).
 *
 * The checks are deliberately cheap — two single-row queries — so the
 * endpoint can sit behind an external uptime monitor on a short
 * interval without load concerns:
 *
 *  1. database reachable: SELECT 1 on the default connection;
 *  2. active fiscal year present: the same tblyears contract the `fy`
 *     middleware enforces for every authenticated page
 *     (years_flg != 0 AND years_status = 'a') — without it every
 *     dashboard 500s even though the DB itself is fine, so the probe
 *     must catch it.
 */
class HealthChecks
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::select('select 1');

        $fy = DB::table('financial_years')
            ->where('years_flg', '!=', 0)
            ->where('years_status', 'a')
            ->first();

        if ($fy === null) {
            throw new RuntimeException(
                'No active financial year. Ask the administrator to set one (Masters → Year Setting).'
            );
        }
    }
}
