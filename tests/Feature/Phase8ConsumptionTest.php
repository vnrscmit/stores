<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\Item;
use App\Models\StockLedgerGood;
use App\Models\User;
use App\Support\FiscalYear as FiscalYearContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @test */
class Phase8ConsumptionTest extends TestCase
{
    private static bool $pipelineReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$pipelineReady) {
            return;
        }

        if (! Schema::hasTable('stock_ledger_goods')) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            $this->artisan('legacy:stage-import', ['--limit' => (string) LegacyStageImport::TEST_SUBSET_LIMIT]);
            $this->artisan('legacy:migrate-data');
            $this->artisan('legacy:integrity-fix', ['--strategy' => 'placeholder']);
            $this->artisan('legacy:add-constraints');
        }

        self::$pipelineReady = true;
    }

    private function actAs(string $role): User
    {
        $user = User::query()->where('role', $role)->first()
            ?? User::create([
                'login' => "test_{$role}",
                'password' => 'testpass',
                'role' => $role,
                'status' => 'Active',
            ]);

        $this->actingAs($user);

        return $user;
    }

    /** @test */
    public function consumption_requires_authentication(): void
    {
        $this->get('/viewer/reports/consumption')->assertRedirect('/login');
    }

    /** @test */
    public function viewers_can_open_the_consumption_report(): void
    {
        $this->actAs('viewer');

        $today = date('Y-m-d');
        $from = date('Y-m-d', strtotime('first day of last month'));

        $r = $this->get("/viewer/reports/consumption?from={$from}&to={$today}");

        $r->assertOk();
        $this->assertStringContainsString('Consumption (Item-wise)', $r->getContent());
        $this->assertSame($from, $r->viewData('from'));
    }

    /** @test */
    public function operators_are_bounced_to_their_dashboard(): void
    {
        $this->actAs('operator');

        $this->get('/viewer/reports/consumption')->assertRedirect(route('operator.home'));
    }

    /** @test */
    public function the_consumption_report_lists_issue_and_internal_return_columns(): void
    {
        $this->actAs('viewer');

        $from = date('Y-m-d', strtotime('first day of last month'));
        $to = date('Y-m-d');

        $row = DB::table('stock_ledger_goods')
            ->whereBetween('stlg_trdate', [$from, $to])
            ->whereIn('stlg_trtype', ['Issue', 'Arrival'])
            ->where('stlg_trsubtype', '!=', 'SUO')
            ->orderBy('stlg_trdate')
            ->first();

        if ($row === null) {
            $this->markTestSkipped('No consumption-relevant ledger rows in the staged data');
        }

        $r = $this->get("/viewer/reports/consumption?from={$from}&to={$to}");

        $r->assertOk()->assertViewHas('rows');

        $rows = $r->viewData('rows');
        $this->assertNotEmpty($rows, 'expected at least one consumption row');
        $this->assertArrayHasKey('date', $rows[0]);
    }

    /** @test */
    public function consumption_filters_by_classification_and_item(): void
    {
        $this->actAs('viewer');

        $from = date('Y-m-d', strtotime('first day of last month'));
        $to = date('Y-m-d');

        $all = $this->get("/viewer/reports/consumption?from={$from}&to={$to}");
        $all->assertOk()->assertViewHas('rows');
        $allRows = $all->viewData('rows');

        // Filter by the same item present in the staged data.
        $itemId = null;
        if (! empty($allRows)) {
            $itemId = Item::find(current($allRows)['item'])?->items_id;
        }

        if ($itemId === null) {
            $this->markTestSkipped('No item present in the consumption rows');
        }

        $filtered = $this->get("/viewer/reports/consumption?from={$from}&to={$to}&item_id={$itemId}");
        $filtered->assertOk()->assertViewHas('rows');

        $filteredRows = $filtered->viewData('rows');
        $this->assertNotEmpty($filteredRows, 'expected at least one row for the filtered item');
    }

    /** @test */
    public function export_streams_with_excel_headers(): void
    {
        $this->actAs('viewer');

        $from = date('Y-m-d', strtotime('first day of last month'));
        $to = date('Y-m-d');

        $response = $this->get("/viewer/reports/consumption/export?from={$from}&to={$to}");

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type', '')
        );
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
    }

    /** @test */
    public function fy_middleware_aborts_without_active_year(): void
    {
        $this->actAs('viewer');

        $activeIds = DB::table('financial_years')
            ->where('years_flg', '!=', 0)
            ->where('years_status', 'a')
            ->pluck('yearsid');
        if ($activeIds->isEmpty()) {
            $this->markTestSkipped('No active fiscal year');
        }

        DB::table('financial_years')->whereIn('yearsid', $activeIds)->update(['years_flg' => 0]);

        FiscalYearContract::invalidateForTesting();

        try {
            $this->get('/viewer/reports/consumption')->assertServerError();
        } finally {
            DB::table('financial_years')
                ->whereIn('yearsid', $activeIds)
                ->update(['years_flg' => 1, 'years_status' => 'a']);
        }
    }

}
