<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\User;
use App\Support\FiscalYear as FiscalYearContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @test */
class Phase8PartywiseTest extends TestCase
{
    private static bool $pipelineReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$pipelineReady) {
            return;
        }

        if (! Schema::hasTable('party_ledgers')) {
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

    /**
     * The legacy dataset is historical, so derive the report window from the
     * staged party-ledger rows themselves instead of assuming a current-year
     * range.
     *
     * @return array{0:string,1:string}
     */
    private function periodFromData(): array
    {
        $from = (string) DB::table('party_ledgers')->min('pldg_trdate');
        $to = (string) DB::table('party_ledgers')->max('pldg_trdate');

        if ($from === '' || $to === '') {
            $this->markTestSkipped('No party-ledger rows in the staged data');
        }

        return [$from, $to];
    }

    /** @test */
    public function partywise_requires_authentication(): void
    {
        $this->get('/viewer/reports/partywise')->assertRedirect('/login');
    }

    /** @test */
    public function viewers_can_open_the_partywise_report(): void
    {
        $this->actAs('viewer');

        $r = $this->get('/viewer/reports/partywise');

        $r->assertOk();
        $this->assertStringContainsString('Party-wise Period Report', $r->getContent());
        $this->assertSame(date('Y-01-01'), $r->viewData('from'));
        $this->assertSame(date('Y-m-d'), $r->viewData('to'));
        $this->assertSame('ALL', $r->viewData('partyName'));
        $this->assertIsArray($r->viewData('rows'));
        $this->assertIsArray($r->viewData('totals'));
    }

    /** @test */
    public function operators_are_bounced_to_their_dashboard(): void
    {
        $this->actAs('operator');

        $this->get('/viewer/reports/partywise')->assertRedirect(route('operator.home'));
    }

    /** @test */
    public function the_partywise_report_lists_ledger_rows_with_the_legacy_column_split(): void
    {
        $this->actAs('viewer');

        [$from, $to] = $this->periodFromData();

        $r = $this->get("/viewer/reports/partywise?from={$from}&to={$to}");

        $r->assertOk()->assertViewHas('rows');

        $rows = $r->viewData('rows');
        $this->assertNotEmpty($rows, 'expected at least one party-ledger row');

        $first = $rows[0];
        foreach ([
            'date', 'particulars', 'item',
            'opening_ups', 'opening_qty',
            'dc_ups', 'dc_qty',
            'good_ups', 'good_qty',
            'arrival_damage_ups', 'arrival_damage_qty',
            'internal_damage_ups', 'internal_damage_qty',
            'excess', 'shortage', 'net_ups', 'net_qty',
            'issue_ups', 'issue_qty',
            'balance_ups', 'balance_qty',
        ] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        // Particulars text follows the legacy mapping.
        $particulars = collect($rows)->pluck('particulars')->unique();
        $known = ['Arrival from Party', 'Material Return to Party', 'Opening Stock', 'Good to Damage - Party'];
        $unknown = $particulars->reject(fn ($p) => str_contains((string) $p, '(') || in_array($p, $known));
        $this->assertCount(0, $unknown, 'unexpected particulars mapping: '.$unknown->implode(', '));

        // Period totals carry the summary contract.
        $totals = $r->viewData('totals');
        foreach ([
            'dc_ups', 'dc_qty', 'good_ups', 'good_qty', 'damage_ups', 'damage_qty', 'excess', 'shortage', 'closing_ups', 'closing_qty',
        ] as $key) {
            $this->assertArrayHasKey($key, $totals);
        }
    }

    /** @test */
    public function the_partywise_report_filters_by_party(): void
    {
        $this->actAs('viewer');

        [$from, $to] = $this->periodFromData();

        $anyPartyId = DB::table('party_ledgers')
            ->whereBetween('pldg_trdate', [$from, $to])
            ->where('pldg_trpartyid', '>', 0)
            ->orderBy('pldg_trpartyid')
            ->value('pldg_trpartyid');

        if ($anyPartyId === null) {
            $this->markTestSkipped('No party-scoped rows in the staged data');
        }

        $partyName = (string) DB::table('parties')
            ->where('p_id', $anyPartyId)
            ->value('business_name');

        $filtered = $this->get("/viewer/reports/partywise?from={$from}&to={$to}&party_id={$anyPartyId}");

        $filtered->assertOk()->assertViewHas('rows');

        $this->assertSame($partyName, $filtered->viewData('partyName'));
        $this->assertNotEmpty($filtered->viewData('rows'), 'expected rows for the filtered party');
    }

    /** @test */
    public function export_streams_with_excel_headers(): void
    {
        $this->actAs('viewer');

        [$from, $to] = $this->periodFromData();

        $response = $this->get("/viewer/reports/partywise/export?from={$from}&to={$to}");

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type', '')
        );
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
        $this->assertStringContainsString(
            'Party_wise_Stock_Report_all_From_'.$from.'_To_'.$to.'.xlsx',
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
            $this->get('/viewer/reports/partywise')->assertServerError();
        } finally {
            DB::table('financial_years')
                ->whereIn('yearsid', $activeIds)
                ->update(['years_flg' => 1, 'years_status' => 'a']);
        }
    }
}
