<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\User;
use App\Support\FiscalYear as FiscalYearContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @test */
class Phase8DiscardTest extends TestCase
{
    private static bool $pipelineReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$pipelineReady) {
            return;
        }

        if (! Schema::hasTable('discards')) {
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
    public function discard_requires_authentication(): void
    {
        $this->get('/viewer/reports/discard')->assertRedirect('/login');
    }

    /** @test */
    public function viewers_can_open_the_discard_report(): void
    {
        $this->actAs('viewer');

        $r = $this->get('/viewer/reports/discard');

        $r->assertOk();
        $this->assertStringContainsString('Discard Report', $r->getContent());
        $this->assertSame(date('Y-01-01'), $r->viewData('from'));
        $this->assertSame(date('Y-m-d'), $r->viewData('to'));
    }

    /** @test */
    public function operators_are_bounced_to_their_dashboard(): void
    {
        $this->actAs('operator');

        $this->get('/viewer/reports/discard')->assertRedirect(route('operator.home'));
    }

    /** @test */
    public function the_discard_report_lists_ledger_rows(): void
    {
        $this->actAs('viewer');

        [$from, $to] = $this->periodFromData();

        $seed = DB::table('stock_ledger_damages')
            ->whereBetween('stld_trdate', [$from, $to])
            ->where('stld_trtype', 'Discard')
            ->where('stld_trsubtype', 'MD')
            ->count();

        if ($seed === 0) {
            $this->markTestSkipped('No Discard (MD) rows in the staged data');
        }

        $r = $this->get("/viewer/reports/discard?from={$from}&to={$to}");

        $r->assertOk()->assertViewHas('rows');

        $rows = $r->viewData('rows');
        $this->assertNotEmpty($rows, 'expected at least one discard row');

        $first = $rows[0];
        foreach (['date', 'particulars', 'classification', 'item', 'ups', 'qty'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
        $this->assertGreaterThan(0.0, $first['qty']);
    }

    /** @test */
    public function the_discard_report_filters_by_classification(): void
    {
        $this->actAs('viewer');

        [$from, $to] = $this->periodFromData();

        $anyClassId = DB::table('stock_ledger_damages')
            ->whereBetween('stld_trdate', [$from, $to])
            ->where('stld_trtype', 'Discard')
            ->where('stld_trsubtype', 'MD')
            ->where('stld_trclassid', '>', 0)
            ->orderBy('stld_trclassid')
            ->value('stld_trclassid');

        if ($anyClassId === null) {
            $this->markTestSkipped('No classification-scoped discard rows in the staged data');
        }

        $className = (string) DB::table('classifications')
            ->where('classification_id', $anyClassId)
            ->value('classification');

        $filtered = $this->get("/viewer/reports/discard?from={$from}&to={$to}&classification_id={$anyClassId}");

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

        [$from, $to] = $this->periodFromData();

        $response = $this->get("/viewer/reports/discard/export?from={$from}&to={$to}");

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type', '')
        );
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
        $this->assertStringContainsString(
            'Discard_Report_From_'.$from.'_To_'.$to.'.xlsx',
            $response->headers->get('Content-Disposition', '')
        );
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
            $this->get('/viewer/reports/discard')->assertServerError();
        } finally {
            DB::table('financial_years')
                ->whereIn('yearsid', $activeIds)
                ->update(['years_flg' => 1, 'years_status' => 'a']);
        }
    }

    /**
     * The legacy dataset is historical, so derive the report window from the
     * staged discard rows themselves instead of assuming a current-year range.
     *
     * @return array{0:string,1:string}
     */
    private function periodFromData(): array
    {
        $from = (string) DB::table('stock_ledger_damages')
            ->where('stld_trtype', 'Discard')
            ->where('stld_trsubtype', 'MD')
            ->min('stld_trdate');
        $to = (string) DB::table('stock_ledger_damages')
            ->where('stld_trtype', 'Discard')
            ->where('stld_trsubtype', 'MD')
            ->max('stld_trdate');

        if ($from === '' || $to === '') {
            $this->markTestSkipped('No discard movements in the staged data');
        }

        return [$from, $to];
    }
}
