<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\AuditLog;
use App\Models\Captive;
use App\Models\CaptiveItem;
use App\Models\CaptiveSloc;
use App\Models\Issue;
use App\Models\IssueItem;
use App\Models\IssueSloc;
use App\Models\Item;
use App\Models\Party;
use App\Models\StockLedgerGood;
use App\Models\User;
use App\Support\EIssueStatus;
use App\Support\FiscalYear;
use App\Support\IssueNumbering;
use App\Support\IssueTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 7 acceptance: the three self-contained issue types (pindent,
 * stocktr, mrtv) and captive consumption (CC). Legacy flow (entry screen ->
 * per-line SLOC distribution -> final post) reusing the Phase-6 posting
 * skeleton, with per-type committed serials and StockLedgerService as the
 * single stock writer.
 */
class Phase7MovementTypesTest extends TestCase
{
    private static bool $pipelineReady = false;

    private string $refNonce;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$pipelineReady) {
            if (! Schema::hasTable('users')) {
                $this->artisan('migrate:fresh', ['--force' => true]);
                $this->artisan('legacy:stage-import', ['--limit' => (string) LegacyStageImport::TEST_SUBSET_LIMIT]);
                $this->artisan('legacy:migrate-data');
                $this->artisan('legacy:integrity-fix', ['--strategy' => 'placeholder']);
                $this->artisan('legacy:integrity-fix', ['--strategy' => 'null']);
                $this->artisan('legacy:add-constraints');
            }

            // Apply pending migrations added after this database was built.
            $this->artisan('migrate', ['--force' => true]);

            self::$pipelineReady = true;
        }

        // Hermeticity: this suite shares the migrated test database across
        // runs, so remove artifacts previous runs posted (issues/captives,
        // their distribution rows and their ledger rows). Balances revert
        // to the migrated baseline because the posted rows are gone.
        $fy = FiscalYear::yearcode();

        $testIssueIds = Issue::query()
            ->whereIn('issue_type', IssueTypes::all())
            ->where('yearcode', $fy)
            ->pluck('issue_id');

        // Ledger out-rows are the only stock side effect of posting: the
        // ledger is append-only (StockLedgerService reads the latest
        // remaining row's balance), so deleting the rows previous runs
        // posted reverts every item x location balance to its pre-run
        // value. The picked distribution rows are never mutated by
        // posting, so no source-row restoration is needed.
        IssueSloc::query()->whereIn('issue_tr_id', $testIssueIds)->delete();
        IssueItem::query()->whereIn('issue_id', $testIssueIds)->delete();
        StockLedgerGood::query()
            ->where('stlg_trtype', 'Issue')
            ->whereIn('stlg_trsubtype', IssueTypes::all())
            ->whereIn('stlg_trid', $testIssueIds)
            ->delete();
        Issue::query()->whereIn('issue_id', $testIssueIds)->delete();

        $testCaptiveIds = Captive::query()->where('yearcode', $fy)->pluck('tid');

        CaptiveSloc::query()->whereIn('issue_trid', $testCaptiveIds)->delete();
        CaptiveItem::query()->whereIn('id_in', $testCaptiveIds)->delete();
        StockLedgerGood::query()
            ->where('stlg_trtype', 'CC')->where('stlg_trsubtype', 'CC')
            ->whereIn('stlg_trid', $testCaptiveIds)
            ->delete();
        Captive::query()->whereIn('tid', $testCaptiveIds)->delete();

        // Hermeticity across suites: the fixture reference tokens below are
        // the same literals Phase 6's stock-transfer test posts, so a
        // freshly-migrated DB that still carries a Phase 6 row would fool
        // findFixtureIssue. Derive a per-run nonce so each process family
        // only sees its own open headers.
        $this->refNonce = (string) microtime(true);

        // Re-run the pipeline if a prior migrate:fresh (in this or another
        // process) wiped the test DB — the static gate alone isn't enough
        // because PHPUnit may fork per-test processes.
        if (! Schema::hasTable('items') || StockLedgerGood::query()->count() === 0) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            $this->artisan('legacy:stage-import', ['--limit' => (string) LegacyStageImport::TEST_SUBSET_LIMIT]);
            $this->artisan('legacy:migrate-data');
            $this->artisan('legacy:integrity-fix', ['--strategy' => 'placeholder']);
            $this->artisan('legacy:integrity-fix', ['--strategy' => 'null']);
            $this->artisan('legacy:add-constraints');
            $this->artisan('migrate', ['--force' => true]);
        }
    }

    private function operator(): User
    {
        $user = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /**
     * An item whose stock row has a sane (UPS <= qty) positive balance,
     * returning [item, ledger]. Prefer rows produced by an arrival so the
     * test's posting complement doesn't collide with other tests that reuse
     * the same item in sequence.
     *
     * The migrated data can have arrival ledgers pointing to In-Active items,
     * so join items here and require Active at the query level — picking a
     * non-Active item would make every test that calls this helper fail at
     * the controller's own actstatus gate.
     */
    private function stockedItem(): array
    {
        $ledger = StockLedgerGood::query()
            ->join('items', 'items.items_id', '=', 'stock_ledger_goods.stlg_tritemid')
            ->where('stock_ledger_goods.stlg_balqty', '>', 0)
            ->whereColumn('stock_ledger_goods.stlg_balups', '<=', 'stock_ledger_goods.stlg_balqty')
            ->where('stock_ledger_goods.stlg_tritemid', '!=', 0)
            ->where('stock_ledger_goods.stlg_trtype', 'Arrival')
            ->where('items.actstatus', 'Active')
            ->orderByDesc('stock_ledger_goods.stlg_id')
            ->firstOrFail();

        $item = Item::query()
            ->where('items_id', $ledger->stlg_tritemid)
            ->where('actstatus', 'Active')
            ->firstOrFail();

        return [$item, $ledger];
    }

    /**
     * The test fixture's header reference per type. Legacy parity: the
     * stock-transfer header stores its reference in strefno (tblissue
     * strefno column); pindent/mrtv use dcrefno.
     */
    private function findFixtureIssue(string $type): Issue
    {
        // Legacy data may carry the same strefno/dcrefno on posted rows,
        // so narrow to open (unposted) issues of the current year so the
        // lookup always lands on the row the test just created.
        return Issue::query()
            ->where('issue_type', $type)
            ->where('yearcode', FiscalYear::yearcode())
            ->where('issuetrflag', 0)
            ->where($type === IssueTypes::STOCK_TRANSFER ? 'strefno' : 'dcrefno',
                $this->fixtureRef($type === IssueTypes::STOCK_TRANSFER ? 'TR-77' : 'PI-IND-77'))
            ->firstOrFail();
    }

    private function storeLine(string $type, int $itemId, int $classificationId, float $qty, int $stlgId): TestResponse
    {
        return $this->postJson("/issues/{$type}/lines", [
            'dcrefno' => $type === 'stocktr' ? 'ST-REF-77' : $this->fixtureRef('PI-IND-77'),
            'strefno' => $type === 'pindent' ? 'Store Keeper' : $this->fixtureRef('TR-77'),
            'issue_date' => now()->toDateString(),
            'strdate' => now()->toDateString(),
            'party_id' => Party::query()->orderBy('p_id')->value('p_id'),
            'rettyp' => 'Returnable',
            'tmode' => 'By Hand',
            'pname_byhand' => 'Ravi',
            'remarks' => 'Phase 7 test',
            'classification_id' => $classificationId,
            'items_id' => $itemId,
            'ups' => 1,
            'qty' => $qty,
            'uom' => 'Number',
            'slocs' => [['stlg_id' => $stlgId, 'ups' => 1, 'qty' => $qty]],
        ]);
    }

    /**
     * Stamps the per-run nonce onto the fixture reference token so the
     * open-header lookup (findFixtureIssue) never lands on a row posted by
     * a different suite sharing the migrated test database.
     */
    private function fixtureRef(string $base): string
    {
        return $base.'-'.$this->refNonce;
    }

    /**
     * The opening balance the StockLedgerService will read for this item at
     * this location. Parity with StockLedgerService::latestRow(): reads the
     * absolute latest row (any trtype) so the test agrees with the service on
     * the starting balance — migrated data can carry CI/cycle-count rows
     * between the arrival and the test's post that change the balance.
     */
    private function openingBalanceFor(int $itemId, int $whid, int $binid, int $subbinid): float
    {
        $row = StockLedgerGood::query()
            ->where('stlg_tritemid', $itemId)
            ->where('stlg_whid', $whid)
            ->where('stlg_binid', $binid)
            ->where('stlg_subbinid', $subbinid)
            ->orderByDesc('stlg_id')
            ->first();

        return (float) ($row?->stlg_balqty ?? 0);
    }

    /** @test */
    public function movement_routes_require_the_operator_role(): void
    {
        foreach ([...IssueTypes::all(), 'cc'] as $type) {
            $this->get("/issues/{$type}")->assertRedirect('/login');
        }

        $raiser = User::query()->where('role', 'eindent')->firstOrFail();
        $this->actingAs($raiser);
        $this->get('/issues/pindent')->assertForbidden();
        $this->get('/issues/cc')->assertForbidden();

        $this->operator();
        foreach ([...IssueTypes::all(), 'cc'] as $type) {
            $this->get("/issues/{$type}")->assertOk();
            $this->get("/issues/{$type}/new")->assertOk();
        }
    }

    /** @test */
    public function first_line_post_creates_a_typed_header_with_a_workspace_serial(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        $this->assertNotEmpty($item->actstatus, 'item actstatus is empty');
        $this->assertSame('Active', $item->actstatus, 'stockedItem returned non-active item');

        $response = $this->storeLine(IssueTypes::PHYSICAL_INDENT, $item->items_id, $item->classification_id, 1.0, (int) $ledger->stlg_id);
        $response->assertOk();

        $issue = Issue::query()
            ->where('issue_type', IssueTypes::PHYSICAL_INDENT)
            ->where('yearcode', FiscalYear::yearcode())
            ->where('dcrefno', $this->fixtureRef('PI-IND-77'))
            ->firstOrFail();

        $this->assertSame(0, (int) $issue->issuetrflag);
        $this->assertSame(EIssueStatus::OPEN, $issue->status);
        $this->assertSame('Store Keeper', $issue->strefno);
        $this->assertSame('By Hand', $issue->tmode);
        $this->assertGreaterThan(0, (int) $issue->issue_code);

        // One line + one distribution row were written.
        $this->assertSame(1, IssueItem::query()->where('issue_id', $issue->issue_id)->count());
        $this->assertSame(1, IssueSloc::query()
            ->where('issue_tr_id', $issue->issue_id)
            ->where('issue_type', IssueTypes::PHYSICAL_INDENT)
            ->count());

        // The response redirects to the typed workspace.
        $response->assertJsonPath('redirect', route('issues.'.IssueTypes::PHYSICAL_INDENT.'.workspace', $issue));
    }

    /** @test */
    public function line_distribution_must_match_the_line_quantity(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        // Distribution sums 1.0 but the line claims 2.0 -> rejected.
        $response = $this->postJson('/issues/pindent/lines', [
            'dcrefno' => $this->fixtureRef('PI-IND-77'), 'strefno' => 'Store Keeper',
            'issue_date' => now()->toDateString(),
            'tmode' => 'By Hand',
            'classification_id' => $item->classification_id,
            'items_id' => $item->items_id,
            'ups' => 1,
            'qty' => 2.0,
            'uom' => 'Number',
            'slocs' => [['stlg_id' => (int) $ledger->stlg_id, 'ups' => 1, 'qty' => 1.0]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must equal the line quantity', (string) $response->json('message'));
    }

    /** @test */
    public function distribution_may_not_exceed_the_live_ledger_balance(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        $response = $this->postJson('/issues/pindent/lines', [
            'dcrefno' => $this->fixtureRef('PI-IND-77'), 'strefno' => 'Store Keeper',
            'issue_date' => now()->toDateString(),
            'tmode' => 'By Hand',
            'classification_id' => $item->classification_id,
            'items_id' => $item->items_id,
            'ups' => 1,
            'qty' => (float) $ledger->stlg_balqty + 5,
            'uom' => 'Number',
            'slocs' => [['stlg_id' => (int) $ledger->stlg_id, 'ups' => 1, 'qty' => (float) $ledger->stlg_balqty + 5]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('exceeds the available balance', (string) $response->json('message'));
    }

    /** @test */
    public function an_item_must_belong_to_the_selected_classification(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        $wrongClassification = (int) $item->classification_id === 1 ? 2 : 1;

        $response = $this->postJson('/issues/pindent/lines', [
            'dcrefno' => $this->fixtureRef('PI-IND-77'), 'strefno' => 'Store Keeper',
            'issue_date' => now()->toDateString(),
            'tmode' => 'By Hand',
            'classification_id' => $wrongClassification,
            'items_id' => $item->items_id,
            'ups' => 1,
            'qty' => 1.0,
            'uom' => 'Number',
            'slocs' => [['stlg_id' => (int) $ledger->stlg_id, 'ups' => 1, 'qty' => 1.0]],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('does not belong', (string) $response->json('message'));
    }

    /** @test */
    public function final_post_writes_ledger_rows_and_assigns_the_committed_serial(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        $response = $this->storeLine(IssueTypes::STOCK_TRANSFER, $item->items_id, $item->classification_id, 1.0, (int) $ledger->stlg_id);
        $response->assertOk();
        $createdId = (int) $response->json('issue_id');

        $issue = $this->findFixtureIssue(IssueTypes::STOCK_TRANSFER);
        $this->assertSame($createdId, (int) $issue->issue_id, 'findFixtureIssue returned wrong issue: expected '.$createdId.', got '.$issue->issue_id);

        // Parity with StockLedgerService::latestRow(): the opening balance the
        // post will read is the latest row for the item+location, which may
        // differ from the arrival ledger stockedItem() returned if the
        // migrated data carries intermediate CI/adjustment rows.
        $openQty = $this->openingBalanceFor($item->items_id, (int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) ($ledger->stlg_subbinid ?? 0));

        $this->post(route('issues.'.IssueTypes::STOCK_TRANSFER.'.post', $issue))->assertRedirect();

        $issue->refresh();

        $this->assertSame(1, (int) $issue->issuetrflag);
        $this->assertSame(EIssueStatus::POSTED, $issue->status);
        $this->assertGreaterThan(0, (int) $issue->iss_code);
        $this->assertGreaterThan(0, (int) $issue->ncode);

        // The ledger got one out-row with the type's trsubtype and the
        // location's balance reduced by the issued quantity. Read the row
        // freshly so the assertion fails loudly if a previous test left a
        // stale out-row with the same trid/tritemid (e.g. a legacy issue).
        DB::beginTransaction();
        $row = StockLedgerGood::query()
            ->where('stlg_trtype', 'Issue')
            ->where('stlg_trsubtype', IssueTypes::STOCK_TRANSFER)
            ->where('stlg_trid', $issue->issue_id)
            ->where('stlg_tritemid', $item->items_id)
            ->orderByDesc('stlg_id')
            ->lockForUpdate()
            ->firstOrFail();
        DB::rollBack();

        $this->assertGreaterThan(0, (float) $row->stlg_opqty, 'posted row opqty must be positive');
        $this->assertGreaterThan(0, (float) $row->stlg_balqty, 'posted row balqty must be positive');
        $this->assertSame((int) $ledger->stlg_whid, (int) $row->stlg_whid, 'warehouse must match');
        $this->assertSame((int) $ledger->stlg_binid, (int) $row->stlg_binid, 'bin must match');
        $this->assertSame((int) ($ledger->stlg_subbinid ?? 0), (int) ($row->stlg_subbinid ?? 0), 'subbin must match');

        $this->assertEqualsWithDelta($openQty - 1.0, (float) $row->stlg_balqty, 0.001, 'balqty mismatch');
        $this->assertEqualsWithDelta($openQty, (float) $row->stlg_opqty, 0.001, 'opqty mismatch');
        $this->assertEqualsWithDelta(1.0, (float) $row->stlg_trqty, 0.001, 'trqty mismatch');
        $this->assertEqualsWithDelta(
            $row->stlg_opqty - $row->stlg_trqty,
            (float) $row->stlg_balqty,
            0.001,
            'balqty must equal opqty - trqty'
        );

        // The committed display uses the legacy IS prefix for stock transfers.
        $this->assertSame(
            sprintf('IS%d/%s/%s', $issue->iss_code, $issue->yearcode, $issue->issue_role),
            IssueNumbering::committedId($issue)
        );

        // Reposting is rejected.
        $this->post(route('issues.'.IssueTypes::STOCK_TRANSFER.'.post', $issue))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /** @test */
    public function posting_requires_every_line_to_be_distributed(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        // Post with no lines at all -> 422.
        $response = $this->storeLine(IssueTypes::MRTV, $item->items_id, $item->classification_id, 1.0, (int) $ledger->stlg_id);
        $response->assertOk();

        $issue = $this->findFixtureIssue(IssueTypes::MRTV);

        // Remove the line so the issue has no distribution.
        $line = IssueItem::query()->where('issue_id', $issue->issue_id)->firstOrFail();
        $this->deleteJson('/issues/mrtv/lines/'.$line->issuesub_id)->assertOk();

        $this->post(route('issues.mrtv.post', $issue))->assertStatus(422);
    }

    /** @test */
    public function cc_transactions_get_their_own_family_and_serials(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        $response = $this->postJson('/issues/cc/lines', [
            'tdate' => now()->toDateString(),
            'party_mode' => 'manual',
            'party_name' => 'Walk-in Department',
            'rettyp' => 'Not Returnable',
            'tmode' => 'By Hand',
            'pname' => 'Ravi',
            'remarks' => 'Phase 7 CC',
            'classification_id' => $item->classification_id,
            'items_id' => $item->items_id,
            'uom' => 'Number',
            'type' => 'good',
            'slocs' => [['stlg_id' => (int) $ledger->stlg_id, 'ups' => 1, 'qty' => 1.0]],
        ]);
        $response->assertOk();

        $captive = Captive::query()
            ->where('yearcode', FiscalYear::yearcode())
            ->where('party_name', 'Walk-in Department')
            ->firstOrFail();

        $this->assertSame(0, (int) $captive->ccflg);

        // Line quantity = distribution sum (CC has no entered qty).
        $line = CaptiveItem::query()->where('id_in', $captive->tid)->firstOrFail();
        $this->assertSame(1.0, (float) $line->qty);

        $openQty = $this->openingBalanceFor($item->items_id, (int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) ($ledger->stlg_subbinid ?? 0));

        $this->post(route('issues.cc.post', $captive))->assertRedirect();

        $captive->refresh();
        $this->assertSame(1, (int) $captive->ccflg);
        $this->assertGreaterThan(0, (int) $captive->cc_code);
        $this->assertGreaterThan(0, (int) $captive->ncode);

        DB::beginTransaction();
        $postedRow = StockLedgerGood::query()
            ->where('stlg_trtype', 'CC')->where('stlg_trsubtype', 'CC')
            ->where('stlg_trid', $captive->tid)
            ->where('stlg_tritemid', $item->items_id)
            ->orderByDesc('stlg_id')
            ->lockForUpdate()
            ->firstOrFail();
        DB::rollBack();

        $this->assertGreaterThan(0, (float) $postedRow->stlg_opqty, 'CC posted row opqty must be positive');
        $this->assertGreaterThan(0, (float) $postedRow->stlg_balqty, 'CC posted row balqty must be positive');
        $this->assertSame((int) $ledger->stlg_whid, (int) $postedRow->stlg_whid, 'CC warehouse must match');
        $this->assertSame((int) $ledger->stlg_binid, (int) $postedRow->stlg_binid, 'CC bin must match');
        $this->assertSame((int) ($ledger->stlg_subbinid ?? 0), (int) ($postedRow->stlg_subbinid ?? 0), 'CC subbin must match');

        $this->assertEqualsWithDelta($openQty - 1.0, (float) $postedRow->stlg_balqty, 0.001, 'CC balqty mismatch');
        $this->assertEqualsWithDelta($openQty, (float) $postedRow->stlg_opqty, 0.001, 'CC opqty mismatch');
        $this->assertEqualsWithDelta(1.0, (float) $postedRow->stlg_trqty, 0.001, 'CC trqty mismatch');
        $this->assertEqualsWithDelta(
            $postedRow->stlg_opqty - $postedRow->stlg_trqty,
            (float) $postedRow->stlg_balqty,
            0.001,
            'CC balqty must equal opqty - trqty'
        );

        // Reposting is rejected.
        $this->post(route('issues.cc.post', $captive))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /** @test */
    public function cc_requires_a_party_master_reference_or_manual_details(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        // Neither party_id nor manual details.
        $response = $this->postJson('/issues/cc/lines', [
            'tdate' => now()->toDateString(),
            'party_mode' => 'manual',
            'rettyp' => 'Returnable',
            'tmode' => 'By Hand',
            'classification_id' => $item->classification_id,
            'items_id' => $item->items_id,
            'uom' => 'Number',
            'type' => 'good',
            'slocs' => [['stlg_id' => (int) $ledger->stlg_id, 'ups' => 1, 'qty' => 1.0]],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function posting_is_audited_for_every_movement_type(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        $this->storeLine(IssueTypes::PHYSICAL_INDENT, $item->items_id, $item->classification_id, 1.0, (int) $ledger->stlg_id);
        $issue = $this->findFixtureIssue(IssueTypes::PHYSICAL_INDENT);
        $this->post(route('issues.'.IssueTypes::PHYSICAL_INDENT.'.post', $issue))->assertRedirect();

        $audits = AuditLog::query()
            ->where('module', 'issue.'.IssueTypes::PHYSICAL_INDENT)
            ->whereIn('action', ['open', 'line.create', 'post'])
            ->get();

        $this->assertGreaterThanOrEqual(3, $audits->count());

        $postAudit = $audits->firstWhere('action', 'post');
        $this->assertNotNull($postAudit);
        $this->assertSame('issues', $postAudit->record_type);
    }

    /** @test */
    public function posted_issues_appear_in_the_committed_stage_filter(): void
    {
        [$item, $ledger] = $this->stockedItem();
        $this->operator();

        $this->storeLine(IssueTypes::STOCK_TRANSFER, $item->items_id, $item->classification_id, 1.0, (int) $ledger->stlg_id);
        $issue = $this->findFixtureIssue(IssueTypes::STOCK_TRANSFER);
        $this->post(route('issues.'.IssueTypes::STOCK_TRANSFER.'.post', $issue))->assertRedirect();
        $issue->refresh();

        $this->get('/issues/stocktr?stage=posted')
            ->assertOk()
            ->assertSee(IssueNumbering::committedId($issue));
    }
}
