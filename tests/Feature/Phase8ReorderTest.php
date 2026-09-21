<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\Item;
use App\Models\StockLedgerGood;
use App\Models\User;
use App\Support\FiscalYear as FiscalYearContract;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @test */
class Phase8ReorderTest extends TestCase
{
    private static bool $pipelineReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$pipelineReady) {
            return;
        }

        if (! Schema::hasTable('items')) {
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
    public function reorder_requires_authentication(): void
    {
        $this->get('/viewer/reports/reorder')->assertRedirect('/login');
    }

    /** @test */
    public function viewers_can_open_the_reorder_report(): void
    {
        $this->actAs('viewer');

        $r = $this->get('/viewer/reports/reorder');

        $r->assertOk();
        $this->assertStringContainsString('Reorder Level Report', $r->getContent());
        $this->assertSame(Date::today()->toDateString(), $r->viewData('asOf'));
        $this->assertIsArray($r->viewData('rows'));
    }

    /** @test */
    public function operators_are_bounced_to_their_dashboard(): void
    {
        $this->actAs('operator');

        $this->get('/viewer/reports/reorder')->assertRedirect(route('operator.home'));
    }

    /** @test */
    public function the_reorder_report_lists_tracked_items_at_or_below_their_level(): void
    {
        $this->actAs('viewer');

        // Mirror the reader's gating to find an item the report must list:
        // srl_status = 'Yes' with a reorder level, total latest-row balance
        // across ledger locations at/below that level.
        $candidate = null;

        $tracked = Item::query()
            ->where('srl_status', 'Yes')
            ->whereNotNull('srl')
            ->orderBy('items_id')
            ->get();

        foreach ($tracked as $item) {
            $totqty = 0.0;

            $locations = StockLedgerGood::query()
                ->select('stlg_whid', 'stlg_binid', 'stlg_subbinid')
                ->where('stlg_trclassid', $item->classification_id)
                ->where('stlg_tritemid', $item->items_id)
                ->distinct()
                ->get();

            foreach ($locations as $loc) {
                $latest = StockLedgerGood::query()
                    ->where('stlg_tritemid', $item->items_id)
                    ->where('stlg_whid', $loc->stlg_whid)
                    ->where('stlg_binid', $loc->stlg_binid)
                    ->where('stlg_subbinid', $loc->stlg_subbinid)
                    ->orderByDesc('stlg_id')
                    ->first();

                $totqty += (float) $latest?->stlg_balqty;
            }

            if ($totqty <= (float) $item->srl) {
                $candidate = $item;

                break;
            }
        }

        if ($candidate === null) {
            $this->markTestSkipped('No tracked item at/below its reorder level in the staged data');
        }

        $r = $this->get('/viewer/reports/reorder');

        $r->assertOk()->assertViewHas('rows');

        $rows = $r->viewData('rows');
        $this->assertNotEmpty($rows, 'expected at least one reorder row');

        $matched = collect($rows)->first(fn ($row) => $row['item'] === $candidate->stores_item);
        $this->assertNotNull($matched, 'tracked item below level missing from report');
        $this->assertSame((float) $candidate->srl, $matched['reorder_level']);
        $this->assertLessThanOrEqual($matched['reorder_level'], $matched['qty']);
        $this->assertContains($matched['remarks'], ['R', 'OR']);
    }

    /** @test */
    public function every_listed_row_respects_the_reorder_gate(): void
    {
        $this->actAs('viewer');

        $r = $this->get('/viewer/reports/reorder');

        $r->assertOk()->assertViewHas('rows');

        foreach ($r->viewData('rows') as $row) {
            $item = Item::query()->where('stores_item', $row['item'])->first();

            $this->assertNotNull($item, 'report row for unknown item '.$row['item']);
            $this->assertSame('Yes', $item->srl_status, 'untracked item listed');
            $this->assertLessThanOrEqual((float) $item->srl, $row['qty']);
        }
    }

    /** @test */
    public function export_streams_with_excel_headers(): void
    {
        $this->actAs('viewer');

        $response = $this->get('/viewer/reports/reorder/export');

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type', '')
        );
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
        $this->assertStringContainsString(
            'reorder-level-'.Date::today()->toDateString().'.xlsx',
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
            $this->get('/viewer/reports/reorder')->assertServerError();
        } finally {
            DB::table('financial_years')
                ->whereIn('yearsid', $activeIds)
                ->update(['years_flg' => 1, 'years_status' => 'a']);
        }
    }
}
