<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\Bin;
use App\Models\Item;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\FiscalYear as FiscalYearContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 8 acceptance: the sub-bin stock card (bincard) report — role
 * isolation, card output over the migrated ledger, XLSX export headers,
 * as-of filtering, dropdown filtering, and FY middleware.
 */
class Phase8BincardTest extends TestCase
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
    public function bincard_requires_authentication(): void
    {
        $this->get('/viewer/reports/bincard')->assertRedirect('/login');
    }

    /** @test */
    public function viewers_can_open_the_bincard(): void
    {
        $this->actAs('viewer');
        $this->get('/viewer/reports/bincard')->assertOk()->assertSee('Sub-Bin Card');
    }

    /** @test */
    public function operators_are_bounced_to_their_dashboard(): void
    {
        $this->actAs('operator');
        $this->get('/viewer/reports/bincard')->assertRedirect(route('operator.home'));
    }

    /** @test */
    public function the_card_lists_items_with_a_positive_balance(): void
    {
        $this->actAs('viewer');

        $subBin = $this->anyStockedSubBin();
        if ($subBin === null) {
            $this->markTestSkipped('No stocked sub-bin in the staged data');
        }

        $response = $this->get(
            '/viewer/reports/bincard'.
            '?warehouse_id='.$subBin->whid.
            '&bin_id='.$subBin->binid.
            '&subbin_id='.$subBin->sid
        );

        $response->assertOk();

        // The chosen sub-bin is shown.
        $this->assertStringContainsString($subBin->sname, $response->getContent());

        // The card lists items — at least the count of items with positive
        // balance at this sub-bin appears as table rows.
        $count = StockLedgerGood::query()
            ->where('stlg_whid', $subBin->whid)
            ->where('stlg_binid', $subBin->binid)
            ->where('stlg_subbinid', $subBin->sid)
            ->where('stlg_balqty', '>', 0)
            ->where('stlg_trdate', '<=', date('Y-m-d'))
            ->distinct('stlg_tritemid')
            ->count('stlg_tritemid');

        if ($count > 0) {
            $response->assertViewHas('rows');
            $this->assertGreaterThan(0, count($response->viewData('rows')));
        }
    }

    /** @test */
    public function the_card_shows_a_per_item_movement_ledger(): void
    {
        $this->actAs('viewer');

        $subBin = $this->anyStockedSubBin();
        if ($subBin === null) {
            $this->markTestSkipped('No stocked sub-bin in the staged data');
        }

        // Pick an item that is stocked here.
        $item = StockLedgerGood::query()
            ->where('stlg_whid', $subBin->whid)
            ->where('stlg_binid', $subBin->binid)
            ->where('stlg_subbinid', $subBin->sid)
            ->where('stlg_balqty', '>', 0)
            ->where('stlg_trdate', '<=', date('Y-m-d'))
            ->orderByDesc('stlg_id')
            ->first();

        if ($item === null) {
            $this->markTestSkipped('No stocked item at the chosen sub-bin');
        }

        $response = $this->get(
            '/viewer/reports/bincard'.
            '?warehouse_id='.$subBin->whid.
            '&bin_id='.$subBin->binid.
            '&subbin_id='.$subBin->sid
        );

        $response->assertOk();

        // The item's stores_item code appears in the card.
        $itemMaster = Item::query()->where('items_id', $item->stlg_tritemid)->first();
        $this->assertNotNull($itemMaster);
        $this->assertStringContainsString($itemMaster->stores_item, $response->getContent());
    }

    /** @test */
    public function as_of_date_filters_the_card(): void
    {
        $this->actAs('viewer');

        $subBin = $this->anyStockedSubBin();
        if ($subBin === null) {
            $this->markTestSkipped('No stocked sub-bin in the staged data');
        }

        $today = date('Y-m-d');

        // Without an explicit as_of, the card uses today.
        $r1 = $this->get(
            '/viewer/reports/bincard'.
            '?warehouse_id='.$subBin->whid.
            '&bin_id='.$subBin->binid.
            '&subbin_id='.$subBin->sid
        );
        $r1->assertOk();
        $this->assertSame($today, $r1->viewData('asOf'));

        // Explicit as_of is passed through.
        $r2 = $this->get(
            '/viewer/reports/bincard'.
            '?warehouse_id='.$subBin->whid.
            '&bin_id='.$subBin->binid.
            '&subbin_id='.$subBin->sid.
            '&as_of=2020-06-15'
        );
        $r2->assertOk();
        $this->assertSame('2020-06-15', $r2->viewData('asOf'));
    }

    /** @test */
    public function export_streams_with_excel_headers(): void
    {
        $this->actAs('viewer');

        $subBin = $this->anyStockedSubBin();
        if ($subBin === null) {
            $this->markTestSkipped('No stocked sub-bin in the staged data');
        }

        $response = $this->get(
            '/viewer/reports/bincard/export'.
            '?warehouse_id='.$subBin->whid.
            '&bin_id='.$subBin->binid.
            '&subbin_id='.$subBin->sid
        );

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type', '')
        );
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
    }

    /** @test */
    public function choosing_a_warehouse_filters_the_bin_dropdown(): void
    {
        $this->actAs('viewer');

        $wh = Warehouse::query()->whereHas('bins.subBins')->first();
        if ($wh === null) {
            $this->markTestSkipped('No warehouse with bins in the staged data');
        }

        $r = $this->get('/viewer/reports/bincard?warehouse_id='.$wh->whid);
        $r->assertOk();

        $bins = $r->viewData('bins');
        $this->assertNotEmpty($bins);
        $this->assertTrue($bins->every(fn ($b) => $b->whid === $wh->whid));
    }

    /** @test */
    public function choosing_a_bin_filters_the_subbin_dropdown(): void
    {
        $this->actAs('viewer');

        $bin = Bin::query()->has('subBins')->first();
        if ($bin === null) {
            $this->markTestSkipped('No bin with sub-bins in the staged data');
        }

        $r = $this->get('/viewer/reports/bincard?bin_id='.$bin->binid);
        $r->assertOk();

        $subbins = $r->viewData('subbins');
        $this->assertNotEmpty($subbins);
        $this->assertTrue($subbins->every(fn ($s) => $s->binid === $bin->binid));
    }

    /** @test */
    public function fy_middleware_aborts_without_active_year(): void
    {
        $this->actAs('viewer');

        $activeIds = DB::table('financial_years')->where('years_flg', '!=', 0)->where('years_status', 'a')->pluck('yearsid');
        if ($activeIds->isEmpty()) {
            $this->markTestSkipped('No active fiscal year');
        }

        DB::table('financial_years')->whereIn('yearsid', $activeIds)->update(['years_flg' => 0]);

        FiscalYearContract::invalidateForTesting();

        try {
            $this->get('/viewer/reports/bincard')->assertServerError();
        } finally {
            DB::table('financial_years')->whereIn('yearsid', $activeIds)->update(['years_flg' => 1, 'years_status' => 'a']);
        }
    }

    /** @test */
    public function export_filename_reflects_the_chosen_sub_bin_and_date(): void
    {
        $this->actAs('viewer');

        $subBin = $this->anyStockedSubBin();
        if ($subBin === null) {
            $this->markTestSkipped('No stocked sub-bin in the staged data');
        }

        $response = $this->get(
            '/viewer/reports/bincard/export'.
            '?warehouse_id='.$subBin->whid.
            '&bin_id='.$subBin->binid.
            '&subbin_id='.$subBin->sid.
            '&as_of=2024-03-15'
        );

        $this->assertStringContainsString(
            'bincard-'.$subBin->whid.'-'.$subBin->binid.'-'.$subBin->sid.'-2024-03-15.xlsx',
            $response->headers->get('Content-Disposition', '')
        );
    }

    /**
     * Return any sub-bin that holds a positive balance as-of today AND has a
     * warehouse and bin assigned, or null when the staged data has none.
     */
    private function anyStockedSubBin(): ?SubBin
    {
        $row = DB::table('sub_bins')
            ->join('stock_ledger_goods', 'stock_ledger_goods.stlg_subbinid', '=', 'sub_bins.sid')
            ->where('stock_ledger_goods.stlg_balqty', '>', 0)
            ->where('stock_ledger_goods.stlg_trdate', '<=', date('Y-m-d'))
            ->whereNotNull('sub_bins.whid')
            ->whereNotNull('sub_bins.binid')
            ->orderBy('sub_bins.whid')
            ->orderBy('sub_bins.binid')
            ->orderBy('sub_bins.sid')
            ->first(['sub_bins.sid']);

        if ($row === null) {
            return null;
        }

        return SubBin::query()->where('sid', $row->sid)->first();
    }
}
