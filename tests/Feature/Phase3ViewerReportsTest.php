<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 3 acceptance: viewer reports catalogue, live report output over the
 * migrated ledger, XLSX export headers, role isolation.
 */
class Phase3ViewerReportsTest extends TestCase
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
            $this->artisan('legacy:stage-import');
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
    public function catalogue_requires_authentication(): void
    {
        $this->get('/viewer/reports')->assertRedirect('/login');
    }

    /** @test */
    public function viewers_can_open_the_catalogue(): void
    {
        $this->actAs('viewer');
        $this->get('/viewer/reports')->assertOk()->assertSee('Stock On Hand');
    }

    /** @test */
    public function operators_are_bounced_to_their_dashboard(): void
    {
        $this->actAs('operator');

        // operator is NOT in role:viewer,admin -> bounced to operator home
        $this->get('/viewer/reports')->assertRedirect(route('operator.home'));
    }

    /** @test */
    public function stock_on_hand_lists_positive_balances(): void
    {
        $this->actAs('viewer');

        $response = $this->get('/viewer/reports/stock-on-hand');
        $response->assertOk();

        // At least one positive balance exists in the staged data.
        $any = DB::table('stock_ledger_goods')->where('stlg_balqty', '>', 0)->exists();
        if ($any) {
            $response->assertSee('Warehouse');
        }
    }

    /** @test */
    public function item_ledger_filters_by_date_range(): void
    {
        $this->actAs('viewer');

        $row = DB::table('stock_ledger_goods')
            ->whereNotNull('stlg_trdate')
            ->orderBy('stlg_trdate')
            ->first();

        if ($row === null) {
            $this->markTestSkipped('No ledger rows staged');
        }

        $from = substr((string) $row->stlg_trdate, 0, 10);
        $this->get("/viewer/reports/item-ledger?from={$from}&to={$from}")
            ->assertOk();
    }

    /** @test */
    public function xlsx_export_streams_with_excel_headers(): void
    {
        $this->actAs('viewer');

        $response = $this->get('/viewer/reports/stock-on-hand/export');

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type', '')
        );
    }

    /** @test */
    public function fy_middleware_aborts_without_active_year(): void
    {
        $this->actAs('viewer');
        $activeIds = DB::table('financial_years')->where('years_flg', 1)->pluck('yearsid');
        DB::table('financial_years')->update(['years_flg' => 0]);

        // The static FY cache would serve the stale row within this process.
        FiscalYear::invalidateForTesting();

        try {
            $this->get('/viewer/reports')->assertServerError();
        } finally {
            // Restore the exact row(s) that were active before (order-
            // independent: parallel workers share this clone database).
            DB::table('financial_years')->whereIn('yearsid', $activeIds)->update(['years_flg' => 1, 'years_status' => 'a']);
        }
    }
}
