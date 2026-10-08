<?php

namespace Tests\Feature;

use App\Models\FinancialYear;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The /up health probe (bootstrap/app.php health route + the
 * App\Support\HealthChecks DiagnosingHealth listener): 200 when the DB
 * is reachable AND an active fiscal year exists, 500 otherwise.
 */
class HealthEndpointTest extends TestCase
{
    public function test_answers_ok_when_the_database_and_active_fiscal_year_are_fine(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_fails_when_no_active_fiscal_year_exists(): void
    {
        // Deactivate every year — exactly the state the health check
        // exists to catch (all dashboards 500, DB itself fine).
        FinancialYear::query()->update(['years_flg' => 0, 'years_status' => 'u']);

        try {
            $this->get('/up')->assertServerError();
        } finally {
            FinancialYear::query()
                ->where('ycode', '22-23')
                ->update(['years_flg' => 1, 'years_status' => 'a']);
        }
    }

    public function test_fails_when_the_database_is_unreachable(): void
    {
        // Point the default connection at a port nothing listens on and
        // purge the cached PDO so the next query must reconnect.
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');

        config(['database.connections.mysql.port' => '35971']);
        DB::purge();

        try {
            $this->get('/up')->assertServerError();
        } finally {
            config([
                'database.connections.mysql.host' => $host,
                'database.connections.mysql.port' => $port,
            ]);
            DB::purge();
        }
    }
}
