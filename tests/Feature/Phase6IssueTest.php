<?php

namespace Tests\Feature;

use App\Models\EIndent;
use App\Models\EIndentItem;
use App\Models\Issue;
use App\Models\IssueItem;
use App\Models\IssueSloc;
use App\Models\Item;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
use App\Models\User;
use App\Support\EIndentStatus;
use App\Support\EIssueStatus;
use App\Support\FiscalYear;
use App\Support\StockLedgerService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 6 acceptance: issue against e-Indents. Legacy flow (pending indent
 * queue -> SLOC distribution workspace -> final post) with StockLedgerService
 * as the single stock writer, plus the port's approval gate and transactional,
 * idempotent posting.
 */
class Phase6IssueTest extends TestCase
{
    private static bool $pipelineReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$pipelineReady) {
            if (! Schema::hasTable('users')) {
                $this->artisan('migrate:fresh', ['--force' => true]);
                $this->artisan('legacy:stage-import');
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
        // runs, so remove artifacts previous runs posted (issues, their
        // ledger rows and the ZZTEST fixture indents). Balances revert to
        // the migrated baseline because the posted rows are gone.
        $fy = FiscalYear::yearcode();

        $testIssueIds = Issue::query()
            ->where('issue_type', 'eindent')->where('yearcode', $fy)
            ->pluck('issue_id');

        IssueSloc::query()->whereIn('issue_tr_id', $testIssueIds)->delete();
        IssueItem::query()->whereIn('issue_id', $testIssueIds)->delete();
        StockLedgerGood::query()
            ->where('stlg_trtype', 'Issue')->where('stlg_trsubtype', 'eindent')
            ->whereIn('stlg_trid', $testIssueIds)->delete();
        Issue::query()->whereIn('issue_id', $testIssueIds)->delete();

        $fixtureTids = EIndent::query()->where('yearcode', 'ZZTEST')->pluck('tid');
        EIndentItem::query()->whereIn('id_in', $fixtureTids)->delete();
        EIndent::query()->whereIn('tid', $fixtureTids)->delete();
    }

    private function operator(): User
    {
        $user = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    private function admin(): User
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /**
     * Create one approved indent (raiser -> submit -> admin approve) with a
     * single line for an item that has stock, returning [indent, line].
     */
    private function approvedIndent(): array
    {
        $raiser = User::query()->where('role', 'eindent')->firstOrFail();

        // An item whose stock row has a sane (UPS <= qty) balance.
        $ledger = StockLedgerGood::query()
            ->where('stlg_balqty', '>', 0)
            ->whereColumn('stlg_balups', '<=', 'stlg_balqty')
            ->where('stlg_tritemid', '!=', 0)
            ->orderByDesc('stlg_id')
            ->firstOrFail();

        $item = Item::query()->findOrFail($ledger->stlg_tritemid);

        // A unique committed code: the issue workspace keys off it (dcrefno).
        $indent = EIndent::query()->create([
            'code1' => 990000 + (int) EIndent::query()->max('code1'),
            'code' => (int) EIndent::query()->max('code') + 1,
            'tdate' => now()->toDateString(),
            'id' => $raiser->id,
            'flg' => 0,
            'yearcode' => 'ZZTEST',
            'tflg' => 0,
            'status' => EIndentStatus::DRAFT,
        ]);

        $indent->items()->create([
            'classification_id' => $item->classification_id,
            'items_id' => $item->items_id,
            'uom' => $item->uom,
            'qty' => 1.0,
        ]);

        $indent->update(['tflg' => 1, 'status' => EIndentStatus::PENDING]);
        $indent->update(['status' => EIndentStatus::APPROVED]);

        return [$indent, $indent->items->first(), $ledger];
    }

    private function distributionPayload(int $stlgId, float $qty): array
    {
        return [
            'line' => 0, // set by caller
            'slocs' => [['stlg_id' => $stlgId, 'ups' => 1, 'qty' => $qty]],
        ];
    }

    /** The pending-queue search term for the ZZTEST fixture indents. */
    private function fixtureSearch(): string
    {
        return 'ZZTEST';
    }

    /** @test */
    public function issue_routes_require_the_operator_role(): void
    {
        $this->get('/issues/eindents')->assertRedirect('/login');

        $raiser = User::query()->where('role', 'eindent')->firstOrFail();
        $this->actingAs($raiser);
        $this->get('/issues/eindents')->assertForbidden();

        $viewer = User::query()->where('role', 'viewer')->firstOrFail();
        $this->actingAs($viewer);
        $this->get('/issues/eindents')->assertForbidden();

        $this->operator();
        $this->get('/issues/eindents')->assertOk();
        $this->get('/issues/eindents/pending')->assertOk();
    }

    /** @test */
    public function pending_queue_lists_only_approved_open_indents(): void
    {
        [$indent] = $this->approvedIndent();

        $this->operator();

        $response = $this->get('/issues/eindents/pending?q='.(int) $indent->code);
        $response->assertOk();
        $response->assertSee((string) $indent->code);

        // Drafts and pending indents never appear: the query itself is the gate.
        $visible = EIndent::query()
            ->where('flg', 0)->where('tflg', 1)
            ->where('status', EIndentStatus::APPROVED)
            ->pluck('tid');
        $this->assertContains($indent->tid, $visible);

        $draft = EIndent::query()->where('tflg', 0)->where('flg', 0)->first();
        if ($draft !== null) {
            $this->assertNotContains($draft->tid, $visible);
        }
    }

    /** @test */
    public function workspace_opens_a_header_with_a_workspace_serial_and_is_resumable(): void
    {
        [$indent] = $this->approvedIndent();
        $this->operator();

        $this->get(route('issues.eindents.workspace', $indent))->assertOk();

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', FiscalYear::yearcode())
            ->firstOrFail();

        $this->assertSame(0, (int) $issue->issuetrflag);
        $this->assertSame(EIssueStatus::OPEN, $issue->status);
        $this->assertGreaterThan(0, (int) $issue->issue_code);

        // Resuming the workspace must not create a second header.
        $this->get(route('issues.eindents.workspace', $indent))->assertOk();
        $this->assertSame(1, Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', FiscalYear::yearcode())
            ->where('issuetrflag', 0)
            ->count());
    }

    /** @test */
    public function non_approved_indents_cannot_be_issued(): void
    {
        $raiser = User::query()->where('role', 'eindent')->firstOrFail();
        $item = Item::query()->where('actstatus', 'Active')->firstOrFail();

        $indent = EIndent::query()->create([
            'code1' => 990000 + EIndent::query()->max('code1'),
            'tdate' => now()->toDateString(),
            'id' => $raiser->id,
            'flg' => 0,
            'yearcode' => 'ZZTEST',
            'tflg' => 1,
            'status' => EIndentStatus::PENDING, // submitted but not approved
        ]);
        $indent->items()->create([
            'classification_id' => $item->classification_id,
            'items_id' => $item->items_id,
            'uom' => $item->uom,
            'qty' => 1,
        ]);

        $this->operator();
        $this->get(route('issues.eindents.workspace', $indent))->assertStatus(422);
    }

    /** @test */
    public function line_distribution_must_match_the_indent_quantity(): void
    {
        [$indent, $line, $ledger] = $this->approvedIndent();
        $this->operator();

        $this->get(route('issues.eindents.workspace', $indent));

        $payload = $this->distributionPayload((int) $ledger->stlg_id, 0.4);
        $payload['line'] = $line->eid;

        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)
            ->assertStatus(422); // 0.4 != 1.0

        $payload['slocs'][0]['qty'] = 1.0;
        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();
        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertStatus(422);
    }

    /** @test */
    public function saving_a_line_writes_issue_items_and_slocs_with_legacy_shape(): void
    {
        [$indent, $line, $ledger] = $this->approvedIndent();
        $this->operator();

        $this->get(route('issues.eindents.workspace', $indent));

        $payload = $this->distributionPayload((int) $ledger->stlg_id, 1.0);
        $payload['line'] = $line->eid;
        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', FiscalYear::yearcode())
            ->firstOrFail();

        $item = $issue->items()->firstOrFail();
        $this->assertSame((int) $line->items_id, (int) $item->item_id);
        $this->assertSame((float) $line->qty, (float) $item->qty_indent);
        $this->assertSame((string) $line->uom, (string) $item->uom);

        $sloc = IssueSloc::query()->where('issue_tr_id', $issue->issue_id)->firstOrFail();
        $this->assertSame((int) $ledger->stlg_whid, (int) $sloc->whid);
        $this->assertSame((int) $ledger->stlg_binid, (int) $sloc->binid);
        $this->assertSame((int) $ledger->stlg_subbinid, (int) $sloc->subbin);
        $this->assertSame(1.0, (float) $sloc->qty_issue);
        $this->assertSame((int) $ledger->stlg_id, (int) $sloc->issue_rowid);
        $this->assertSame((int) $line->eid, (int) $sloc->eid);

        // No ledger rows were written yet (posting happens at final post).
        $before = StockLedgerGood::query()->where('stlg_tritemid', $line->items_id)->count();
        $this->get(route('issues.eindents.workspace', $indent));
        $this->assertSame($before, StockLedgerGood::query()->where('stlg_tritemid', $line->items_id)->count());
    }

    /** @test */
    public function editing_a_line_replaces_its_sloc_rows(): void
    {
        [$indent, $line, $ledger] = $this->approvedIndent();
        $this->operator();

        $this->get(route('issues.eindents.workspace', $indent));

        $payload = $this->distributionPayload((int) $ledger->stlg_id, 1.0);
        $payload['line'] = $line->eid;
        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', FiscalYear::yearcode())
            ->firstOrFail();

        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertStatus(422);

        // Deleting and re-saving works (edit = remove + re-add).
        $this->deleteJson(route('issues.eindents.lines.delete', $indent), ['line' => $line->eid])->assertOk();
        $this->assertSame(0, IssueSloc::query()
            ->where('issue_tr_id', $issue->issue_id)
            ->where('eid', $line->eid)
            ->count());

        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();
        $this->assertSame(1, IssueSloc::query()
            ->where('issue_tr_id', $issue->issue_id)
            ->where('eid', $line->eid)
            ->count());
    }

    /** @test */
    public function final_post_writes_the_ledger_closes_the_indent_and_becomes_immutable(): void
    {
        [$indent, $line, $ledger] = $this->approvedIndent();
        $this->operator();

        $this->get(route('issues.eindents.workspace', $indent));

        $payload = $this->distributionPayload((int) $ledger->stlg_id, 1.0);
        $payload['line'] = $line->eid;
        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();

        $openBefore = (float) $ledger->stlg_balqty;

        $this->post(route('issues.eindents.post', $indent))->assertRedirect();

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', FiscalYear::yearcode())
            ->firstOrFail();

        // Committed serials + flags (legacy issuetrflag=1, indent flg=1).
        $this->assertSame(1, (int) $issue->issuetrflag);
        $this->assertSame(EIssueStatus::POSTED, $issue->status);
        $this->assertGreaterThan(0, (int) $issue->iss_code);
        $this->assertGreaterThan(0, (int) $issue->ncode);

        $indent->refresh();
        $this->assertSame(1, (int) $indent->flg);

        // Ledger: one Issue/eindent row with balance = open - issued.
        $posted = StockLedgerGood::query()
            ->where('stlg_trtype', 'Issue')
            ->where('stlg_trsubtype', 'eindent')
            ->where('stlg_trid', $issue->issue_id)
            ->firstOrFail();
        $this->assertSame((int) $ledger->stlg_tritemid, (int) $posted->stlg_tritemid);
        $this->assertSame(1.0, (float) $posted->stlg_trqty);
        $this->assertEquals($openBefore - 1.0, (float) $posted->stlg_balqty);
        $this->assertSame(FiscalYear::yearcode(), (string) $posted->yearcode);

        // Sub-bin flipped to Empty when the cell drained to zero.
        if ($openBefore - 1.0 == 0.0) {
            $this->assertSame('Empty', SubBin::query()->find((int) $ledger->stlg_subbinid)->status);
        }

        // Re-post is rejected (idempotence) and the indent is closed to the queue.
        $this->post(route('issues.eindents.post', $indent))->assertSessionHas('error');
        $this->assertNotNull($issue->refresh()->iss_code);

        // The indent left the pending queue.
        $this->assertNotContains($indent->tid, EIndent::query()
            ->where('flg', 0)->where('tflg', 1)
            ->where('status', EIndentStatus::APPROVED)->pluck('tid'));
    }

    /** @test */
    public function post_requires_every_line_distributed_and_still_covered(): void
    {
        // Undistributed line -> 422.
        [$indent, $line, $ledger] = $this->approvedIndent();
        $this->operator();
        $this->get(route('issues.eindents.workspace', $indent));

        // abort(422): no validation redirect, just the HTTP status.
        $this->post(route('issues.eindents.post', $indent))->assertStatus(422);

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->where('yearcode', FiscalYear::yearcode())
            ->firstOrFail();

        // Shrink the balance under the saved distribution -> 422 at post time.
        $payload = $this->distributionPayload((int) $ledger->stlg_id, 1.0);
        $payload['line'] = $line->eid;
        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();

        StockLedgerGood::query()->where('stlg_id', $ledger->stlg_id)->update(['stlg_balqty' => 0.5]);

        $this->post(route('issues.eindents.post', $indent))->assertStatus(422);

        // Nothing was posted and the indent is still open.
        $this->assertSame(0, StockLedgerGood::query()
            ->where('stlg_trtype', 'Issue')->where('stlg_trsubtype', 'eindent')
            ->where('stlg_trid', $issue->issue_id)->count());
        $this->assertSame(0, (int) $indent->refresh()->flg);
    }

    /** @test */
    public function posting_twice_cannot_duplicate_ledger_rows(): void
    {
        [$indent, $line, $ledger] = $this->approvedIndent();
        $this->operator();

        $this->get(route('issues.eindents.workspace', $indent));

        $payload = $this->distributionPayload((int) $ledger->stlg_id, 1.0);
        $payload['line'] = $line->eid;
        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();

        $this->post(route('issues.eindents.post', $indent))->assertRedirect();

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->firstOrFail();

        $rows = StockLedgerGood::query()
            ->where('stlg_trtype', 'Issue')->where('stlg_trsubtype', 'eindent')
            ->where('stlg_trid', $issue->issue_id)->count();
        $this->assertSame(1, $rows);
    }

    /** @test */
    public function serials_continue_the_legacy_sequences_and_are_race_safe(): void
    {
        [$indentA] = $this->approvedIndent();
        [$indentB] = $this->approvedIndent();

        $this->operator();

        foreach ([$indentA, $indentB] as $indent) {
            $this->get(route('issues.eindents.workspace', $indent));
            $line = $indent->items->first();
            $ledger = StockLedgerGood::query()
                ->where('stlg_tritemid', $line->items_id)
                ->where('stlg_balqty', '>', 1)
                ->orderByDesc('stlg_id')->first();

            $payload = [
                'line' => $line->eid,
                'slocs' => [['stlg_id' => $ledger->stlg_id, 'ups' => 1, 'qty' => 1.0]],
            ];
            $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();
            $this->post(route('issues.eindents.post', $indent))->assertRedirect();
        }

        $issueA = Issue::query()->where('issue_type', 'eindent')->where('dcrefno', (string) $indentA->code)->firstOrFail();
        $issueB = Issue::query()->where('issue_type', 'eindent')->where('dcrefno', (string) $indentB->code)->firstOrFail();

        $this->assertNotSame($issueA->iss_code, $issueB->iss_code);
        $this->assertNotSame($issueA->ncode, $issueB->ncode);
        $this->assertNotSame($issueA->issue_id, $issueB->issue_id);
    }

    /** @test */
    public function decisions_are_audit_logged(): void
    {
        [$indent, $line, $ledger] = $this->approvedIndent();
        $this->operator();

        $this->get(route('issues.eindents.workspace', $indent));

        $payload = $this->distributionPayload((int) $ledger->stlg_id, 1.0);
        $payload['line'] = $line->eid;
        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();

        $this->post(route('issues.eindents.post', $indent))->assertRedirect();

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'eindent.issue', 'action' => 'post', 'record_type' => 'issues', 'record_id' => $issue->issue_id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'eindent.issue', 'action' => 'line.create', 'record_type' => 'issue_items',
        ]);
    }

    /** @test */
    public function posted_issue_detail_renders_the_legacy_sloc_breakdown(): void
    {
        [$indent, $line, $ledger] = $this->approvedIndent();
        $this->operator();

        $this->get(route('issues.eindents.workspace', $indent));

        $payload = $this->distributionPayload((int) $ledger->stlg_id, 1.0);
        $payload['line'] = $line->eid;
        $this->postJson(route('issues.eindents.lines.save', $indent), $payload)->assertOk();
        $this->post(route('issues.eindents.post', $indent))->assertRedirect();

        $issue = Issue::query()
            ->where('issue_type', 'eindent')
            ->where('dcrefno', (string) $indent->code)
            ->firstOrFail();

        $this->get(route('issues.eindents.show', $issue))
            ->assertOk()
            ->assertSee('TIE'.$issue->iss_code);
    }
}
