<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\Discard;
use App\Models\DiscardItem;
use App\Models\DiscardSloc;
use App\Models\FinancialYear;
use App\Models\GatePass;
use App\Models\Item;
use App\Models\StockLedgerDamage;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\FiscalYear;
use App\Support\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 9 slice 6 acceptance: the Material Discard adjustment module
 * (legacy add_material_discard.php + getuser_discard3.php +
 * add_discard_str_preview.php).
 *
 * Posting semantics under test:
 *  - damage-ledger OUT rows, trtype 'Discard', subtype 'MD', trid = the
 *    discard document id, partyid = party_name;
 *  - opening = the referenced damage row's balance; bal = op − tr with the
 *    legacy UPS normalization (balqty>0 && balups==0 → 1; balqty==0 → 0);
 *  - the sub-bin empties ONLY when no location hosting other items of the
 *    classification holds a positive latest balance (legacy scoped check);
 *  - no reorder pass (the legacy discard preview never calls one);
 *  - dd_code/ncode from the discard/discard.n counters (legacy MAX+1 per
 *    yearcode), ddflg=1, gate-pass row (gpcode, trid "MD{dd_code}").
 */
class Phase9DiscardTest extends TestCase
{
    private static bool $pipelineReady = false;

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

        // Hermeticity: remove artifacts previous runs posted.
        $this->sweepArtifacts();

        // Re-run the pipeline if a prior migrate:fresh (in this or another
        // process) wiped the test DB.
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

    protected function tearDown(): void
    {
        // The ledgers are append-only: an earlier test's posted MD row
        // (balqty 0) would otherwise become the latest row at the shared
        // legacy location and poison every later test's opening balances.
        // Sweeping per test reverts every item x location balance to its
        // migrated baseline.
        $this->sweepArtifacts();

        parent::tearDown();
    }

    /** Remove every test-created discard artifact (documents, sloc/item
     * rows, MD ledger rows incl. trid=0 seeds, gate passes, audit rows).
     * Deleting appended ledger rows restores the migrated balances. */
    private function sweepArtifacts(): void
    {
        if (! Schema::hasTable('discards')) {
            return;
        }

        $fy = FiscalYear::yearcode();

        $testDiscardIds = Discard::query()->where('yearcode', $fy)->pluck('tid');

        DiscardSloc::query()->whereIn('discard_trid', $testDiscardIds)->delete();
        DiscardItem::query()->whereIn('did_s', $testDiscardIds)->delete();
        StockLedgerDamage::query()
            ->where('stld_trtype', 'Discard')->where('stld_trsubtype', 'MD')
            ->where(function ($q) use ($testDiscardIds) {
                $q->whereIn('stld_trid', $testDiscardIds)
                    ->orWhere(function ($q2) {
                        // Seed rows this suite creates carry trid=0 and
                        // today's date (real posts always carry a document
                        // id) — swept so they cannot leak into other runs.
                        $q2->where('stld_trid', 0)
                            ->where('stld_trdate', '>=', now()->toDateString());
                    });
            })->delete();
        GatePass::query()->where('yearcode', $fy)->where('trid', 'like', 'MD%')->delete();
        Discard::query()->whereIn('tid', $testDiscardIds)->delete();
        DB::table('audit_logs')->where('module', 'discard.md')->delete();
    }

    private function operator(): User
    {
        $user = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /**
     * An active item with a sane positive damage-ledger row (UPS <= qty)
     * to discard from, returning [item, damage ledger row].
     *
     * Only rows that are the LATEST for their item+location qualify —
     * mirroring getuser_discard_slocshow, which lists MAX(stld_id) per
     * location and requires balqty > 0 on that row. Posting from a
     * superseded row would open at 0 (the later row already zeroed the
     * location) and drive the balance negative.
     */
    private function damagedItem(): array
    {
        $ledger = StockLedgerDamage::query()
            ->join('items', 'items.items_id', '=', 'stock_ledger_damages.stld_tritemid')
            ->where('stock_ledger_damages.stld_balqty', '>', 0)
            ->whereColumn('stock_ledger_damages.stld_balups', '<=', 'stock_ledger_damages.stld_balqty')
            ->where('stock_ledger_damages.stld_tritemid', '!=', 0)
            ->where('items.actstatus', 'Active')
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)
                    ->from('stock_ledger_damages as newer')
                    ->whereColumn('newer.stld_tritemid', 'stock_ledger_damages.stld_tritemid')
                    ->whereColumn('newer.stld_whid', 'stock_ledger_damages.stld_whid')
                    ->whereColumn('newer.stld_binid', 'stock_ledger_damages.stld_binid')
                    ->whereColumn('newer.stld_subbinid', 'stock_ledger_damages.stld_subbinid')
                    ->whereColumn('newer.stld_id', '>', 'stock_ledger_damages.stld_id');
            })
            ->orderByDesc('stock_ledger_damages.stld_id')
            ->firstOrFail();

        $item = Item::query()
            ->where('items_id', $ledger->stld_tritemid)
            ->where('actstatus', 'Active')
            ->firstOrFail();

        return [$item, $ledger];
    }

    /**
     * A second active item of the same classification holding a positive
     * damage balance at a DIFFERENT location (or any same-class item when
     * none exists — the cross-item helper only needs same-class rows).
     */
    private function companionItem(Item $source): Item
    {
        $companion = Item::query()
            ->where('classification_id', $source->classification_id)
            ->where('items_id', '!=', $source->items_id)
            ->where('actstatus', 'Active')
            ->first();

        if ($companion !== null) {
            return $companion;
        }

        $item = new Item;
        $item->classification_id = $source->classification_id;
        $item->stores_item = 'TEST-MD-COMPANION-'.uniqid();
        $item->uom = $source->uom;
        $item->actstatus = 'Active';
        $item->save();

        return $item;
    }

    /**
     * A real sub-bin location distinct from the given sub-bin ids (the
     * migrated sub_bins carry placeholder rows with NULL whid/binid —
     * those are never valid locations).
     *
     * @param  array<int, int>  $excludeSubbinIds
     * @return array{whid: int, binid: int, subbinid: int}
     */
    private function otherRealLocation(array $excludeSubbinIds): array
    {
        $row = DB::table('sub_bins')
            ->where('sid', '!=', 0)
            ->whereNotIn('sid', $excludeSubbinIds ?: [0])
            ->whereNotNull('whid')
            ->whereNotNull('binid')
            ->orderBy('sid')
            ->first(['whid', 'binid', 'sid']);

        $this->assertNotNull($row, 'No real sub-bin location available.');

        return ['whid' => (int) $row->whid, 'binid' => (int) $row->binid, 'subbinid' => (int) $row->sid];
    }

    /** Payload for one discard line against one damage row. */
    private function payload(Item $item, StockLedgerDamage $ledger, float $qty, int $ups = 1, array $over = []): array
    {
        return array_merge([
            'discard_id' => null,
            'tdate' => now()->toDateString(),
            'drno' => 'MD-TEST-REF',
            'party_name' => 'Phase 9 discard test party',
            'rettyp' => 'damage',
            'remarks' => 'Phase 9 slice 6 test',
            'classification_id' => (int) $item->classification_id,
            'items_id' => (int) $item->items_id,
            'rows' => [[
                'stld_id' => (int) $ledger->stld_id,
                'ups_discard' => $ups,
                'qty_discard' => $qty,
            ]],
        ], $over);
    }

    private function saveLine(array $payload): TestResponse
    {
        return $this->postJson(route('discards.lines.store'), $payload);
    }

    public function test_module_requires_authentication(): void
    {
        $this->get(route('discards.index'))->assertRedirect(route('login'));
        $this->postJson(route('discards.lines.store'), [])->assertStatus(401);
    }

    public function test_fy_gate_bounces_without_active_year(): void
    {
        $this->operator();

        $fy = FinancialYear::query()
            ->where('years_flg', '!=', 0)->where('years_status', 'a')->firstOrFail();
        $fy->years_status = 'x';
        $fy->save();
        FiscalYear::invalidateForTesting();

        try {
            $this->get(route('discards.index'))->assertStatus(500);
        } finally {
            $fy->years_status = 'a';
            $fy->save();
            FiscalYear::invalidateForTesting();
        }

        $this->get(route('discards.index'))->assertOk();
    }

    public function test_discard_drains_the_damage_ledger(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $opQty = (float) $ledger->stld_balqty;
        $opUps = (int) $ledger->stld_balups;

        // Full drain — the discard quantity equals the row's balance.
        $resp = $this->saveLine($this->payload($item, $ledger, $opQty, $opUps));
        $resp->assertOk();
        $discardId = (int) $resp->json('discard_id');

        $discard = Discard::query()->findOrFail($discardId);
        $this->assertSame(0, (int) $discard->ddflg);
        $this->assertSame((int) $discard->tid, (int) $discard->tcode);
        $this->assertSame('MD-TEST-REF', (string) $discard->drno);

        // Item totals on the discard_items row (legacy post-save UPDATE).
        $line = DiscardItem::query()->where('did_s', $discardId)->firstOrFail();
        $this->assertEquals($opQty, (float) $line->qty);
        $this->assertEquals($opUps, (int) $line->ups);
        $this->assertSame('damage', (string) $line->type);

        $this->post(route('discards.post', $discard))->assertRedirect();

        $md = StockLedgerDamage::query()
            ->where('stld_trtype', 'Discard')->where('stld_trsubtype', 'MD')
            ->where('stld_trid', $discardId)->firstOrFail();

        $this->assertSame((int) $ledger->stld_whid, (int) $md->stld_whid);
        $this->assertSame((int) $ledger->stld_subbinid, (int) $md->stld_subbinid);
        $this->assertSame((int) $item->items_id, (int) $md->stld_tritemid);
        $this->assertEquals($opQty, (float) $md->stld_opqty);
        $this->assertEquals($opQty, (float) $md->stld_trqty);
        $this->assertEquals(0.0, (float) $md->stld_balqty);
        $this->assertEquals($opUps, (int) $md->stld_opups);
        $this->assertEquals($opUps, (int) $md->stld_trups);
        $this->assertEquals(0, (int) $md->stld_balups);
        $this->assertSame((string) $discard->party_name, (string) $md->stld_trpartyid);

        // Posted markers + committed serials (legacy MAX+1, per year).
        $discard->refresh();
        $this->assertSame(1, (int) $discard->ddflg);
        $this->assertGreaterThan(0, (int) $discard->dd_code);
        $this->assertGreaterThan(0, (int) $discard->ncode);
        $this->assertSame(1, GatePass::query()
            ->where('yearcode', FiscalYear::yearcode())
            ->where('trid', 'MD'.(int) $discard->dd_code)
            ->count());
    }

    public function test_partial_discard_normalizes_ups_and_keeps_stock(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $opQty = (float) $ledger->stld_balqty;
        $opUps = (int) $ledger->stld_balups;

        $resp = $this->saveLine($this->payload($item, $ledger, 1.0, 1));
        $resp->assertOk();
        $discardId = (int) $resp->json('discard_id');

        $this->post(route('discards.post', Discard::query()->findOrFail($discardId)))->assertRedirect();

        $md = StockLedgerDamage::query()
            ->where('stld_trtype', 'Discard')->where('stld_trsubtype', 'MD')
            ->where('stld_trid', $discardId)->firstOrFail();

        $this->assertEquals($opQty - 1.0, (float) $md->stld_balqty);
        $this->assertEquals(max(0, $opUps - 1), (int) $md->stld_balups);
        $this->assertEquals($opQty - 1.0, (float) StockLedgerService::damageBalanceAt(
            (int) $item->items_id,
            (int) $ledger->stld_whid,
            (int) $ledger->stld_binid,
            (int) $ledger->stld_subbinid,
            now()->toDateString()
        )['qty']);
    }

    public function test_empty_flip_is_scoped_across_items_of_the_classification(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();
        $companion = $this->companionItem($item);

        $subbinId = (int) $ledger->stld_subbinid;

        // Deterministic scenario: the companion item (same classification)
        // holds 5.0 at a DIFFERENT real sub-bin (a trid=0 MD seed row —
        // swept by setUp; real posts always carry a document id, so trid=0
        // is unambiguous).
        $otherLoc = $this->otherRealLocation([$subbinId]);
        StockLedgerService::post([
            'direction' => 'in',
            'damage' => true,
            'yearcode' => FiscalYear::yearcode(),
            'trtype' => 'Discard',
            'trsubtype' => 'MD',
            'trid' => 0,
            'trdate' => now()->toDateString(),
            'classid' => (int) $item->classification_id,
            'item_id' => (int) $companion->items_id,
            'whid' => $otherLoc['whid'],
            'binid' => $otherLoc['binid'],
            'subbinid' => $otherLoc['subbinid'],
            'ups' => 1,
            'qty' => 5.0,
        ]);

        // Drain the source item completely via its migrated baseline row.
        $resp = $this->saveLine($this->payload($item, $ledger, (float) $ledger->stld_balqty, (int) $ledger->stld_balups));
        $resp->assertOk();
        $discardId = (int) $resp->json('discard_id');
        $this->post(route('discards.post', Discard::query()->findOrFail($discardId)))->assertRedirect();

        // The item's balance at its location is now zero (the flip
        // condition is met)...
        $latest = StockLedgerService::latestRow(
            (int) $item->items_id,
            (int) $ledger->stld_whid,
            (int) $ledger->stld_binid,
            $subbinId,
            true
        );
        $this->assertNotNull($latest);
        $this->assertEquals(0.0, (float) $latest->stld_balqty);

        // ...but the sub-bin must NOT read 'Empty': a location hosting
        // another item of the same classification still holds a positive
        // latest balance (legacy scoped cross-item check — the DISTINCT
        // locations of OTHER items, latest row per location, balqty > 0).
        $this->assertSame('Good', (string) SubBin::query()->where('sid', $subbinId)->value('status'));
    }

    public function test_discard_beyond_damage_balance_is_rejected(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $this->saveLine($this->payload($item, $ledger, (float) $ledger->stld_balqty + 5.0))
            ->assertStatus(422);

        $fy = FiscalYear::yearcode();
        $this->assertSame(0, Discard::query()->where('yearcode', $fy)->count());
        $this->assertSame(0, StockLedgerDamage::query()
            ->where('stld_trtype', 'Discard')->where('stld_trsubtype', 'MD')
            ->where('stld_trid', '>', 0)
            ->where('yearcode', $fy)->count());
    }

    public function test_line_save_validates_item_and_rows(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        // The row must belong to the item.
        $bad = $this->payload($item, $ledger, 1.0);
        $bad['rows'][0]['stld_id'] = (int) $ledger->stld_id + 1000000;
        $this->postJson(route('discards.lines.store'), $bad)->assertStatus(422);

        // Zero-quantity rows are invalid.
        $bad = $this->payload($item, $ledger, 0.0);
        $this->postJson(route('discards.lines.store'), $bad)->assertStatus(422);

        // Party name is required.
        $bad = $this->payload($item, $ledger, 1.0);
        $bad['party_name'] = '';
        $this->postJson(route('discards.lines.store'), $bad)->assertStatus(422);

        $this->assertSame(0, Discard::query()
            ->where('yearcode', FiscalYear::yearcode())->count());
    }

    public function test_line_edit_and_delete_replace_the_line(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $discardId = (int) $this->saveLine($this->payload($item, $ledger, 1.0))->json('discard_id');
        $did = (int) DiscardItem::query()->where('did_s', $discardId)->value('did');
        $this->assertSame(1, DiscardSloc::query()->where('discard_id', $did)->count());

        // Edit replaces the line's sloc rows (delete-and-reinsert: the
        // line row gets a NEW did, returned in the response).
        $replace = $this->payload($item, $ledger, 2.0, 2, ['discard_id' => $discardId]);
        $editResp = $this->putJson(route('discards.lines.update', $did), $replace);
        $editResp->assertOk();
        $newDid = (int) $editResp->json('did');
        $this->assertNotSame($did, $newDid);
        $line = DiscardItem::query()->findOrFail($newDid);
        $this->assertEquals(2.0, (float) $line->qty);
        $this->assertSame(1, DiscardSloc::query()->where('discard_id', $newDid)->count());
        $this->assertEquals(2.0, (float) DiscardSloc::query()->where('discard_id', $newDid)->value('qty_discard'));
        $this->assertSame(0, DiscardItem::query()->where('did', $did)->count());

        // Delete removes the line and its sloc rows.
        $this->deleteJson(route('discards.lines.delete', $newDid), ['discard_id' => $discardId])->assertOk();
        $this->assertSame(0, DiscardItem::query()->where('did', $newDid)->count());
        $this->assertSame(0, DiscardSloc::query()->where('discard_id', $newDid)->count());
    }

    public function test_posting_is_idempotent(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $discardId = (int) $this->saveLine($this->payload($item, $ledger, 1.0))->json('discard_id');
        $discard = Discard::query()->findOrFail($discardId);

        // The counter persists across tests in the shared database; the
        // idempotency contract is exactly one consumed serial per post.
        $before = DocumentNumber::current('discard', FiscalYear::yearcode());
        $beforeN = DocumentNumber::current('discard.n', FiscalYear::yearcode());

        $this->post(route('discards.post', $discard))->assertRedirect();
        $code = (int) $discard->refresh()->dd_code;
        $this->assertSame($before + 1, DocumentNumber::current('discard', FiscalYear::yearcode()));

        $this->post(route('discards.post', $discard))->assertRedirect();

        $this->assertSame($code, (int) $discard->refresh()->dd_code);
        $this->assertSame(1, StockLedgerDamage::query()
            ->where('stld_trtype', 'Discard')->where('stld_trsubtype', 'MD')
            ->where('stld_trid', $discardId)->count());
        $this->assertSame($before + 1, DocumentNumber::current('discard', FiscalYear::yearcode()));
        $this->assertSame($beforeN + 1, DocumentNumber::current('discard.n', FiscalYear::yearcode()));
    }

    public function test_posted_discard_is_immutable(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $discardId = (int) $this->saveLine($this->payload($item, $ledger, 1.0))->json('discard_id');
        $discard = Discard::query()->findOrFail($discardId);
        $this->post(route('discards.post', $discard))->assertRedirect();

        $did = (int) DiscardItem::query()->where('did_s', $discardId)->value('did');

        // Line edits/deletes are rejected after posting.
        $this->putJson(route('discards.lines.update', $did),
            $this->payload($item, $ledger, 2.0, 2, ['discard_id' => $discardId]))->assertStatus(422);
        $this->deleteJson(route('discards.lines.delete', $did),
            ['discard_id' => $discardId])->assertStatus(422);

        // The header cannot be re-opened either.
        $this->putJson(route('discards.header.update', $discardId),
            ['tdate' => now()->toDateString(), 'party_name' => 'nope'])->assertStatus(422);
    }

    public function test_workspace_and_show_render(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $discardId = (int) $this->saveLine($this->payload($item, $ledger, 1.5))->json('discard_id');

        $this->get(route('discards.workspace', $discardId))
            ->assertOk()
            ->assertSee('Final post')
            ->assertSee('Material Discard');

        $this->post(route('discards.post', Discard::query()->findOrFail($discardId)))->assertRedirect();

        $this->get(route('discards.show', $discardId))
            ->assertOk()
            ->assertSee('TDD');
    }

    public function test_queue_lists_posted_documents(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $discardId = (int) $this->saveLine($this->payload($item, $ledger, 1.0))->json('discard_id');
        $this->post(route('discards.post', Discard::query()->findOrFail($discardId)))->assertRedirect();

        $this->get(route('discards.index'))
            ->assertOk()
            ->assertSee('TDD');
    }

    public function test_numbering_is_isolated_from_other_counters(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $yearcode = FiscalYear::yearcode();
        DocumentNumber::prime('discard', $yearcode, 0);
        DocumentNumber::prime('discard.n', $yearcode, 0);

        // The other counters carry values from earlier runs in the shared
        // database; capture and assert they do not move (differential).
        // gatepass is NOT in this list: a discard post legitimately
        // consumes exactly one gate-pass serial (verified separately).
        $before = collect(['eindent', 'issue.eindent', 'arrival.vendor', 'arrival.stocktr', 'arrival.internal', 'iitr'])
            ->mapWithKeys(fn ($c) => [$c => DocumentNumber::current($c, $yearcode)]);
        $beforeGp = DocumentNumber::current('gatepass', $yearcode);

        $discardId = (int) $this->saveLine($this->payload($item, $ledger, 1.0))->json('discard_id');
        $this->post(route('discards.post', Discard::query()->findOrFail($discardId)))->assertRedirect();

        $discard = Discard::query()->findOrFail($discardId);
        $this->assertSame(1, (int) $discard->dd_code);
        $this->assertSame(1, (int) $discard->ncode);
        $this->assertSame($beforeGp + 1, DocumentNumber::current('gatepass', $yearcode));

        // No other document counter may move for a discard post.
        foreach ($before as $counter => $value) {
            $this->assertSame($value, DocumentNumber::current($counter, $yearcode),
                "The {$counter} counter must be untouched by discard posts.");
        }
    }
}
