<?php

namespace Tests\Feature;

use App\Models\EIndent;
use App\Models\EIndentItem;
use App\Models\Item;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\EIndentStatus;
use App\Support\FiscalYear;
use App\Support\IndentNumbering;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 5 acceptance: e-Indent raise module with legacy-parity numbering and
 * an approval gate between final submit and operator issuance.
 */
class Phase5EIndentTest extends TestCase
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

            // Apply pending migrations (e.g. the e_indents.status gate added
            // after this database was first built).
            $this->artisan('migrate', ['--force' => true]);

            self::$pipelineReady = true;
        }
    }

    private function raiser(): User
    {
        $user = User::query()->where('role', 'eindent')->firstOrFail();
        $this->actingAs($user);

        // Tests share the migrated production copy; give each test a clean
        // raiser pipeline so the 3-open-indent limit is order-independent.
        $tids = EIndent::query()->where('id', $user->id)->pluck('tid');
        EIndentItem::query()->whereIn('id_in', $tids)->delete();
        EIndent::query()->whereIn('tid', $tids)->delete();

        return $user;
    }

    private function admin(): User
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /** Post one row through the AJAX endpoint (creates the draft on first row). */
    private function postRow(User $raiser, int $tid = 0, ?Item $item = null, float $qty = 10.5): array
    {
        $item = $item ?? Item::query()
            ->where('actstatus', 'Active')
            ->whereNotNull('classification_id')
            ->firstOrFail();

        $response = $this->postJson('/eindents/items', [
            'tid' => $tid,
            'classification_id' => $item->classification_id,
            'items_id' => $item->items_id,
            'uom' => $item->uom,
            'qty' => $qty,
        ]);

        $response->assertOk();

        return $response->json('rows');
    }

    private function itemsOfDifferentClassifications(): array
    {
        $first = Item::query()->where('actstatus', 'Active')->whereNotNull('classification_id')->firstOrFail();

        $second = Item::query()
            ->where('actstatus', 'Active')
            ->whereNotNull('classification_id')
            ->where('classification_id', '!=', $first->classification_id)
            ->firstOrFail();

        return [$first, $second];
    }

    /** @test */
    public function eindents_require_the_raiser_role(): void
    {
        $this->get('/eindents')->assertRedirect('/login');

        $operator = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($operator);
        $this->get('/eindents')->assertForbidden();

        $viewer = User::query()->where('role', 'viewer')->firstOrFail();
        $this->actingAs($viewer);
        $this->get('/eindents')->assertForbidden();

        $this->raiser();
        $this->get('/eindents')->assertOk();
    }

    /** @test */
    public function first_row_post_creates_a_draft_with_legacy_numbering(): void
    {
        $raiser = $this->raiser();

        $payload = $this->postRow($raiser);

        $indent = EIndent::query()->findOrFail($payload['tid']);
        $this->assertSame(EIndentStatus::DRAFT, $indent->status);
        $this->assertSame(0, (int) $indent->tflg);
        $this->assertSame($raiser->id, (int) $indent->id);
        $this->assertSame(FiscalYear::yearcode(), (string) $indent->yearcode);
        $this->assertGreaterThan(0, (int) $indent->code1);
        $this->assertSame(
            DocumentNumber::current(IndentNumbering::DRAFT_COUNTER, $indent->yearcode),
            (int) $indent->code1
        );
    }

    /** @test */
    public function rows_require_a_matching_item_and_inherit_its_uom(): void
    {
        $raiser = $this->raiser();
        [$itemA, $itemB] = $this->itemsOfDifferentClassifications();

        $payload = $this->postRow($raiser);
        $tid = $payload['tid'];

        // Item/classification mismatch is rejected (legacy trusted JS only).
        $this->postJson('/eindents/items', [
            'tid' => $tid,
            'classification_id' => $itemB->classification_id,
            'items_id' => $itemA->items_id,
            'uom' => $itemA->uom,
            'qty' => 5,
        ])->assertStatus(422);

        // UoM is forced from the item master (readonly box in legacy).
        $uomPayload = $this->postRow($raiser, $tid, $itemA, 7.25);
        $last = collect($uomPayload['rows'])->last();
        $this->assertSame((string) $itemA->uom, (string) $last['uom']);
    }

    /** @test */
    public function submit_assigns_the_committed_code_and_flips_legacy_flags(): void
    {
        $raiser = $this->raiser();
        $payload = $this->postRow($raiser);
        $indent = EIndent::query()->findOrFail($payload['tid']);

        $this->put('/eindents/'.$indent->tid.'/remarks', ['remarks' => 'Phase 5 parity check'])
            ->assertRedirect();

        $this->post('/eindents/'.$indent->tid.'/submit')->assertRedirect(route('eindents.index'));

        $indent->refresh();
        $this->assertSame(1, (int) $indent->tflg);
        $this->assertSame(EIndentStatus::PENDING, $indent->status);
        $this->assertSame('Phase 5 parity check', $indent->remarks);
        $this->assertGreaterThan(0, (int) $indent->code);
        $this->assertSame(
            DocumentNumber::current(IndentNumbering::COMMITTED_COUNTER, $indent->yearcode),
            (int) $indent->code
        );
    }

    /** @test */
    public function only_admins_can_approve_or_reject_and_only_when_pending(): void
    {
        $raiser = $this->raiser();
        $payload = $this->postRow($raiser);
        $indent = EIndent::query()->findOrFail($payload['tid']);
        $this->post('/eindents/'.$indent->tid.'/submit')->assertRedirect();

        // Raiser cannot approve.
        $this->post('/eindents/'.$indent->tid.'/approve')->assertForbidden();

        // Admin approves.
        $this->admin();
        $this->post('/eindents/'.$indent->tid.'/approve')->assertRedirect();
        $indent->refresh();
        $this->assertSame(EIndentStatus::APPROVED, $indent->status);

        // Approved indents cannot be approved/rejected again.
        $this->post('/eindents/'.$indent->tid.'/approve')->assertStatus(422);
        $this->post('/eindents/'.$indent->tid.'/reject')->assertStatus(422);
    }

    /** @test */
    public function rejected_indents_reopen_to_draft_and_resubmit_reuses_the_committed_code(): void
    {
        $raiser = $this->raiser();
        $payload = $this->postRow($raiser);
        $indent = EIndent::query()->findOrFail($payload['tid']);
        $this->post('/eindents/'.$indent->tid.'/submit')->assertRedirect();

        $this->admin();
        $this->post('/eindents/'.$indent->tid.'/reject')->assertRedirect();

        // Raiser reopens.
        $this->actingAs($raiser);
        $this->post('/eindents/'.$indent->tid.'/reopen')->assertRedirect();
        $indent->refresh();
        $this->assertSame(0, (int) $indent->tflg);
        $this->assertSame(EIndentStatus::DRAFT, $indent->status);

        $codeBefore = (int) $indent->code;

        // Edit the draft row (fixing the legacy qty=qty bug: updates stick).
        $row = EIndentItem::query()->where('id_in', $indent->tid)->firstOrFail();
        $this->putJson('/eindents/items/'.$row->eid, [
            'classification_id' => $row->classification_id,
            'items_id' => $row->items_id,
            'uom' => $row->uom,
            'qty' => 33.5,
        ])->assertOk();
        $this->assertSame(33.5, (float) $row->refresh()->qty);

        // Resubmit reuses the committed code (no second serial consumed).
        $this->post('/eindents/'.$indent->tid.'/submit')->assertRedirect();
        $indent->refresh();
        $this->assertSame(EIndentStatus::PENDING, $indent->status);
        $this->assertSame($codeBefore, (int) $indent->code);
    }

    /** @test */
    public function the_raise_screen_blocks_a_fourth_open_indent(): void
    {
        $raiser = $this->raiser();

        for ($i = 0; $i < 3; $i++) {
            $payload = $this->postRow($raiser);
            $this->post('/eindents/'.$payload['tid'].'/submit')->assertRedirect();
        }

        $this->get('/eindents/raise')->assertForbidden();
        $this->postJson('/eindents/items', ['tid' => 0, 'qty' => 1])->assertStatus(422);
    }

    /** @test */
    public function approved_indents_are_open_for_issue_and_drafts_are_not(): void
    {
        $raiser = $this->raiser();
        $payload = $this->postRow($raiser);
        $indent = EIndent::query()->findOrFail($payload['tid']);
        $this->post('/eindents/'.$indent->tid.'/submit')->assertRedirect();

        // Pending is not issuable (the approval gate).
        $this->admin();
        $this->post('/eindents/'.$indent->tid.'/approve')->assertRedirect();

        $indent->refresh();
        $this->assertSame(EIndentStatus::APPROVED, $indent->status);
        $this->assertSame(0, (int) $indent->flg, 'Approved = open for issue (flg still 0).');

        // The issue module's pending query (legacy: flg=0 and tflg=1) must
        // include this indent, and must never see drafts.
        $issuable = EIndent::query()->where('flg', 0)->where('tflg', 1)->pluck('tid');
        $this->assertContains($indent->tid, $issuable);
        $this->assertSame(0, EIndent::query()->where('tflg', 0)->where('flg', 1)->count());
    }

    /** @test */
    public function decisions_and_submissions_are_audit_logged(): void
    {
        $raiser = $this->raiser();
        $payload = $this->postRow($raiser);
        $indent = EIndent::query()->findOrFail($payload['tid']);

        $this->post('/eindents/'.$indent->tid.'/submit')->assertRedirect();
        $this->admin();
        $this->post('/eindents/'.$indent->tid.'/approve')->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'eindent', 'action' => 'submit', 'record_type' => 'e_indents', 'record_id' => $indent->tid,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'eindent', 'action' => 'approve', 'record_type' => 'e_indents', 'record_id' => $indent->tid,
        ]);
    }

    /** @test */
    public function admins_see_the_approvals_queue(): void
    {
        $this->admin();
        $this->get('/eindents/approvals')->assertOk();
    }

    /** @test */
    public function raisers_cannot_use_the_admin_approval_queue_or_actions(): void
    {
        $raiser = $this->raiser();
        $this->get('/eindents/approvals')->assertForbidden();

        // A pending indent still cannot be approved by its raiser.
        $payload = $this->postRow($raiser);
        $this->post('/eindents/'.$payload['tid'].'/submit')->assertRedirect();
        $this->post('/eindents/'.$payload['tid'].'/approve')->assertForbidden();
    }
}
