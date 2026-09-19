<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\Item;
use App\Models\User;
use App\Support\FiscalYear as FiscalYearContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @test */
class Phase8StockOnHandDamageTest extends TestCase
{
    private static bool $pipelineReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$pipelineReady) {
            return;
        }

        if (! Schema::hasTable('stock_ledger_damages')) {
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
    public function stock_on_hand_damage_requires_authentication(): void
    {
        $this->get('/viewer/reports/stock-on-hand-damage')->assertRedirect('/login');
    }

    /** @test */
    public function viewers_can_open_the_stock_on_hand_damage_report(): void
    {
        $this->actAs('viewer');

        $asOf = date('Y-m-d');

        $r = $this->get("/viewer/reports/stock-on-hand-damage?as_of={$asOf}");

        $r->assertOk();
        $this->assertStringContainsString('Stock On Hand (Damage)', $r->getContent());
        $this->assertSame($asOf, $r->viewData('asOf'));
    }

    /** @test */
    public function operators_are_bounced_to_their_dashboard(): void
    {
        $this->actAs('operator');

        $this->get('/viewer/reports/stock-on-hand-damage')->assertRedirect(route('operator.home'));
    }

    /** @test */
    public function the_damage_report_lists_items_with_positive_damage_balance(): void
    {
        $this->actAs('viewer');

        $asOf = date('Y-m-d');

        $row = DB::table('stock_ledger_damages')
            ->where('stld_trdate', '<=', $asOf)
            ->where('stld_balqty', '>', 0)
            ->orderBy('stld_trdate')
            ->first();

        if ($row === null) {
            $this->markTestSkipped('No damaged-stock rows with positive balance in the staged data');
        }

        $r = $this->get("/viewer/reports/stock-on-hand-damage?as_of={$asOf}");

        $r->assertOk()->assertViewHas('rows');

        $rows = $r->viewData('rows');
        $this->assertNotEmpty($rows, 'expected at least one damaged-stock row');

        $first = $rows[0];
        $this->assertArrayHasKey('classification', $first);
        $this->assertArrayHasKey('item', $first);
        $this->assertArrayHasKey('uom', $first);
        $this->assertArrayHasKey('warehouse', $first);
        $this->assertArrayHasKey('bin', $first);
        $this->assertArrayHasKey('subbin', $first);
        $this->assertArrayHasKey('ups', $first);
        $this->assertArrayHasKey('qty', $first);
        $this->assertGreaterThan(0.0, $first['qty'], 'listed row must have positive qty');
    }

    /** @test */
    public function the_damage_report_filters_by_classification(): void
    {
        $this->actAs('viewer');

        $asOf = date('Y-m-d');

        // Pick a class the way the reader gates rows: an Active item with a
        // positive damaged balance (latest-row-first), so the filter provably
        // yields rows instead of a class whose items are all In-Active.
        $anyClassId = DB::table('stock_ledger_damages as d')
            ->join('items as i', 'i.items_id', '=', 'd.stld_tritemid')
            ->where('d.stld_trdate', '<=', $asOf)
            ->where('d.stld_trclassid', '>', 0)
            ->where('d.stld_balqty', '>', 0)
            ->where('i.actstatus', 'Active')
            ->orderByDesc('d.stld_id')
            ->value('d.stld_trclassid');

        if ($anyClassId === null) {
            $this->markTestSkipped('No classification-scoped damage rows in the staged data');
        }

        $className = (string) DB::table('classifications')
            ->where('classification_id', $anyClassId)
            ->value('classification');

        $filtered = $this->get("/viewer/reports/stock-on-hand-damage?as_of={$asOf}&classification_id={$anyClassId}");

        $filtered->assertOk()->assertViewHas('rows');

        $rows = $filtered->viewData('rows');
        $this->assertNotEmpty($rows, 'expected at least one row for the filtered classification');

        foreach ($rows as $row) {
            $this->assertSame($className, $row['classification']);
        }
    }

    /** @test */
    public function export_streams_with_excel_headers(): void
    {
        $this->actAs('viewer');

        $asOf = date('Y-m-d');

        $response = $this->get("/viewer/reports/stock-on-hand-damage/export?as_of={$asOf}");

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type', '')
        );
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
        $this->assertStringContainsString('stock-on-hand-damage-'.$asOf.'.xlsx', $response->headers->get('Content-Disposition', ''));
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
            $this->get('/viewer/reports/stock-on-hand-damage')->assertServerError();
        } finally {
            DB::table('financial_years')
                ->whereIn('yearsid', $activeIds)
                ->update(['years_flg' => 1, 'years_status' => 'a']);
        }
    }
}
