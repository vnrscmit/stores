<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\Excess;
use App\Models\ExcessItem;
use App\Models\FinancialYear;
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
 * Phase 9 slice 7 acceptance: the Excess/Shortage adjustment module
 * (legacy add_e1.php + add_exsh_preview.php + getuser_exsh_slocshow.php +
 * edit_exsh.php).
 *
 * Posting semantics under test:
 *  - one ledger row per excess_items row, in the ledger the header `typ`
 *    selects ('good' or 'damage'), trtype 'ES', trid = the document id,
 *    no party id;
 *  - the EXCESS side (upsex/qtyex) posts subtype 'ES' with bal = op + ex;
 *    the SHORTAGE side posts subtype 'SH' with bal = op − sh (the legacy
 *    branch keys on upse==0 && qtye==0);
 *  - opening = the referenced row's balance, no UPS normalization, NO
 *    sub-bin status flip (the legacy ES writer never touches tbl_subbin);
 *  - a class-scoped reorder pass runs once per document (verbatim
 *    degenerate semantics — see StockLedgerService::applyReorderFlag);
 *  - escode/ncode from the excess/excess.n counters (legacy MAX+1 per
 *    yearcode), esflg = 1, no gate pass.
 *
 * Hermeticity: the ledgers are append-only, so every test sweeps the ES
 * artifacts it could have created (documents, rows, ES ledger rows, the
 * trid=-901/-902 seed rows this suite posts, audit rows) in setUp AND
 * tearDown — deleting the appended rows reverts every item x location
 * balance to its migrated baseline. The stocked-item helpers exclude
 * srl-tracked items so the reorder pass can never permanently re-flag
 * migrated rows (the reorder test uses a suite-created item instead).
 */
class Phase9ExcessShortageTest extends TestCase
{
    private static bool $pipelineReady = false;

    /** trid markers of the seed rows this suite posts (always swept). */
    private const SEED_TRIDS = [-901, -902];

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
        // See the class docblock: per-test sweep reverts the append-only
        // ledgers to their migrated baselines.
        $this->sweepArtifacts();

        parent::tearDown();
    }

    /** Remove every test-created ES artifact (documents, rows, ledger
     * rows incl. the negative-trid seeds, audit rows). */
    private function sweepArtifacts(): void
    {
        if (! Schema::hasTable('excesses')) {
            return;
        }

        $fy = FiscalYear::yearcode();

        $testExcessIds = Excess::query()->where('yearcode', $fy)->pluck('tid');

        StockLedgerGood::query()
            ->where(function ($q) use ($testExcessIds) {
                $q->where(function ($q1) use ($testExcessIds) {
                    $q1->where('stlg_trtype', 'ES')->whereIn('stlg_trid', $testExcessIds);
                })->orWhere(function ($q2) {
                    // Seed rows this suite creates carry a negative trid
                    // and today's date (real posts always carry a document
                    // id) — swept so they cannot leak into other runs.
                    $q2->whereIn('stlg_trid', self::SEED_TRIDS)
                        ->where('stlg_trdate', '>=', now()->toDateString());
                });
            })->delete();

        StockLedgerDamage::query()
            ->where(function ($q) use ($testExcessIds) {
                $q->where(function ($q1) use ($testExcessIds) {
                    $q1->where('stld_trtype', 'ES')->whereIn('stld_trid', $testExcessIds);
                })->orWhere(function ($q2) {
                    $q2->whereIn('stld_trid', self::SEED_TRIDS)
                        ->where('stld_trdate', '>=', now()->toDateString());
                });
            })->delete();

        ExcessItem::query()->whereIn('esid', $testExcessIds)->delete();
        Excess::query()->whereIn('tid', $testExcessIds)->delete();
        DB::table('audit_logs')->where('module', 'adjustment.es')->delete();
    }

    private function operator(): User
    {
        $user = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /**
     * An active, NOT srl-tracked item holding a positive GOOD-ledger row
     * that is the latest for its location (getuser_exsh_slocshow
     * semantics — a superseded row would open the post at the wrong
     * balance). Returns [item, good ledger row].
     */
    private function stockedItem(): array
    {
        $ledger = StockLedgerGood::query()
            ->join('items', 'items.items_id', '=', 'stock_ledger_goods.stlg_tritemid')
            ->where('stock_ledger_goods.stlg_balqty', '>', 0)
            ->whereColumn('stock_ledger_goods.stlg_balups', '<=', 'stock_ledger_goods.stlg_balqty')
            ->where('stock_ledger_goods.stlg_tritemid', '!=', 0)
            ->where('items.actstatus', 'Active')
            ->where(function ($q) {
                $q->where('items.srl_status', '!=', 'Yes')->orWhereNull('items.srl_status');
            })
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)
                    ->from('stock_ledger_goods as newer')
                    ->whereColumn('newer.stlg_tritemid', 'stock_ledger_goods.stlg_tritemid')
                    ->whereColumn('newer.stlg_whid', 'stock_ledger_goods.stlg_whid')
                    ->whereColumn('newer.stlg_binid', 'stock_ledger_goods.stlg_binid')
                    ->whereColumn('newer.stlg_subbinid', 'stock_ledger_goods.stlg_subbinid')
                    ->whereColumn('newer.stlg_id', '>', 'stock_ledger_goods.stlg_id');
            })
            ->orderByDesc('stock_ledger_goods.stlg_id')
            ->firstOrFail();

        $item = Item::query()
            ->where('items_id', $ledger->stlg_tritemid)
            ->where('actstatus', 'Active')
            ->firstOrFail();

        return [$item, $ledger];
    }

    /**
     * The damage-ledger twin of stockedItem(): an active, NOT srl-tracked
     * item whose latest damage row at its location holds a positive
     * balance (and sane UPS <= qty). Returns [item, damage ledger row].
     */
    private function damagedItem(): array
    {
        $ledger = StockLedgerDamage::query()
            ->join('items', 'items.items_id', '=', 'stock_ledger_damages.stld_tritemid')
            ->where('stock_ledger_damages.stld_balqty', '>', 0)
            ->whereColumn('stock_ledger_damages.stld_balups', '<=', 'stock_ledger_damages.stld_balqty')
            ->where('stock_ledger_damages.stld_tritemid', '!=', 0)
            ->where('items.actstatus', 'Active')
            ->where(function ($q) {
                $q->where('items.srl_status', '!=', 'Yes')->orWhereNull('items.srl_status');
            })
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
     * A real sub-bin location distinct from the given sub-bin ids (the
     * migrated sub_bins carry placeholder rows with NULL whid/binid —
     * those are never valid locations).
     *
     * @param  array<int, int>  $excludeSubbinIds
     * @return array{whid: int, binid: int, subbinid: int}
     */
    private function otherRealLocation(array $excludeSubbinIds): array
    {
        $rows = DB::table('sub_bins')
            ->where('sid', '!=', 0)
            ->whereNotNull('whid')
            ->whereNotNull('binid')
            ->orderBy('sid')
            ->get(['whid', 'binid', 'sid']);

        foreach ($rows as $row) {
            if (in_array((int) $row->sid, $excludeSubbinIds, true)) {
                continue;
            }

            return ['whid' => (int) $row->whid, 'binid' => (int) $row->binid, 'subbinid' => (int) $row->sid];
        }

        $this->fail('No real sub-bin location available.');
    }

    /**
     * Seed a positive good-ledger row for the item at the location via
     * the service (a trid=-901 row — swept by the harness). Legacy seed
     * trtype/subtype; the negative trid marks it as test data.
     */
    private function seedGoodRow(Item $item, array $loc, int $ups, float $qty, int $trid = -901): StockLedgerGood
    {
        return StockLedgerService::post([
            'direction' => 'in',
            'yearcode' => FiscalYear::yearcode(),
            'trtype' => 'ES',
            'trsubtype' => 'SEED',
            'trid' => $trid,
            'trdate' => now()->toDateString(),
            'classid' => (int) $item->classification_id,
            'item_id' => (int) $item->items_id,
            'whid' => $loc['whid'],
            'binid' => $loc['binid'],
            'subbinid' => $loc['subbinid'],
            'ups' => $ups,
            'qty' => $qty,
        ]);
    }

    /** A suite-created item in the source's classification. */
    private function companionItem(Item $source): Item
    {
        $item = new Item;
        $item->classification_id = $source->classification_id;
        $item->stores_item = 'TEST-ES-COMPANION-'.uniqid();
        $item->uom = $source->uom;
        $item->actstatus = 'Active';
        $item->save();

        return $item;
    }

    /** A suite-created srl-tracked item with the given reorder level. */
    private function srlItem(Item $source, int $level): Item
    {
        $item = new Item;
        $item->classification_id = $source->classification_id;
        $item->stores_item = 'TEST-ES-SRL-'.uniqid();
        $item->uom = $source->uom;
        $item->actstatus = 'Active';
        $item->srl = $level;
        $item->srl_status = 'Yes';
        $item->save();

        return $item;
    }

    private function row(int $rowid, int $upsex, float $qtyex, int $upssh = 0, float $qtysh = 0.0): array
    {
        return [
            'rowid' => $rowid,
            'upsex' => $upsex,
            'qtyex' => $qtyex,
            'upssh' => $upssh,
            'qtysh' => $qtysh,
        ];
    }

    private function payload(Item $item, string $typ, array $rows, array $over = []): array
    {
        return array_merge([
            'excess_id' => null,
            'tdate' => now()->toDateString(),
            'classification_id' => (int) $item->classification_id,
            'items_id' => (int) $item->items_id,
            'uom' => (string) $item->uom,
            'typ' => $typ,
            'remarks' => 'Phase 9 slice 7 test',
            'rows' => $rows,
        ], $over);
    }

    private function saveDoc(array $payload): TestResponse
    {
        return $this->postJson(route('exshorts.store'), $payload);
    }

    public function test_module_requires_authentication(): void
    {
        $this->get(route('exshorts.index'))->assertRedirect(route('login'));
        $this->postJson(route('exshorts.store'), [])->assertStatus(401);
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
            $this->get(route('exshorts.index'))->assertStatus(500);
        } finally {
            $fy->years_status = 'a';
            $fy->save();
            FiscalYear::invalidateForTesting();
        }

        $this->get(route('exshorts.index'))->assertOk();
    }

    public function test_availability_endpoint_returns_reference_rows(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->getJson(route('exshorts.availability', $item->items_id).'?typ=good');
        $resp->assertOk()->assertJsonPath('ok', true);

        $rowids = collect($resp->json('rows'))->pluck('rowid')->map(fn ($v) => (int) $v);
        $this->assertContains((int) $ledger->stlg_id, $rowids);

        // The listed row carries the opening balances the post will use.
        $row = collect($resp->json('rows'))->firstWhere('rowid', (int) $ledger->stlg_id);
        $this->assertEquals((float) $ledger->stlg_balqty, (float) $row['qty']);
    }

    public function test_excess_raises_the_good_ledger_balance(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $opQty = (float) $ledger->stlg_balqty;
        $opUps = (int) $ledger->stlg_balups;

        $resp = $this->saveDoc($this->payload($item, 'good', [$this->row((int) $ledger->stlg_id, 2, 3.0)]));
        $resp->assertOk();
        $excessId = (int) $resp->json('excess_id');

        $excess = Excess::query()->findOrFail($excessId);
        $this->assertSame(0, (int) $excess->esflg);
        $this->assertSame('good', (string) $excess->typ);
        $this->assertGreaterThan(0, (int) $excess->code);

        // Header totals (legacy post-save UPDATE).
        $this->assertEquals(2, (int) $excess->ups);
        $this->assertEquals(3.0, (float) $excess->qty);

        // Saved row: location copied from the referenced ledger row, post
        // balance = op + excess (the legacy balups_/balqty_ fields).
        $line = ExcessItem::query()->where('esid', $excessId)->firstOrFail();
        $this->assertSame((int) $ledger->stlg_id, (int) $line->rowid);
        $this->assertSame((int) $ledger->stlg_whid, (int) $line->whid);
        $this->assertSame((int) $ledger->stlg_subbinid, (int) $line->subbinid);
        $this->assertEquals($opUps + 2, (int) $line->balups);
        $this->assertEquals($opQty + 3.0, (float) $line->balqty);

        // Differential counter assertion (counters persist in the shared
        // DB, so prime them to known values first).
        $fy = FiscalYear::yearcode();
        DocumentNumber::prime('excess', $fy, 500);
        DocumentNumber::prime('excess.n', $fy, 700);

        $this->post(route('exshorts.post', $excess))->assertRedirect();

        $es = StockLedgerGood::query()
            ->where('stlg_trtype', 'ES')->where('stlg_trsubtype', 'ES')
            ->where('stlg_trid', $excessId)->firstOrFail();

        $this->assertSame((int) $ledger->stlg_whid, (int) $es->stlg_whid);
        $this->assertSame((int) $ledger->stlg_subbinid, (int) $es->stlg_subbinid);
        $this->assertSame((int) $item->items_id, (int) $es->stlg_tritemid);
        $this->assertSame((int) $item->classification_id, (int) $es->stlg_trclassid);
        $this->assertEquals($opQty, (float) $es->stlg_opqty);
        $this->assertEquals(3.0, (float) $es->stlg_trqty);
        $this->assertEquals($opQty + 3.0, (float) $es->stlg_balqty);
        $this->assertEquals($opUps, (int) $es->stlg_opups);
        $this->assertEquals(2, (int) $es->stlg_trups);
        $this->assertEquals($opUps + 2, (int) $es->stlg_balups);
        // Legacy ES rows carry no party id.
        $this->assertNull($es->stlg_trpartyid);

        // The damage ledger is untouched by a 'good' document.
        $this->assertSame(0, StockLedgerDamage::query()
            ->where('stld_trtype', 'ES')->where('stld_trid', $excessId)->count());

        // Committed serials (differential) + posted marker.
        $excess->refresh();
        $this->assertSame(1, (int) $excess->esflg);
        $this->assertSame(501, (int) $excess->escode);
        $this->assertSame(701, (int) $excess->ncode);

        // Live balance follows the ledger (good-ledger reader).
        $this->assertEquals($opQty + 3.0, (float) StockLedgerService::balanceAt(
            (int) $item->items_id,
            (int) $ledger->stlg_whid,
            (int) $ledger->stlg_binid,
            (int) $ledger->stlg_subbinid,
            now()->toDateString()
        )['qty']);
    }

    public function test_shortage_drains_the_good_ledger(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $opQty = (float) $ledger->stlg_balqty;
        $opUps = (int) $ledger->stlg_balups;

        $resp = $this->saveDoc($this->payload($item, 'good', [$this->row((int) $ledger->stlg_id, 0, 0.0, 1, 1.0)]));
        $resp->assertOk();
        $excessId = (int) $resp->json('excess_id');

        $this->post(route('exshorts.post', Excess::query()->findOrFail($excessId)))->assertRedirect();

        $sh = StockLedgerGood::query()
            ->where('stlg_trtype', 'ES')->where('stlg_trsubtype', 'SH')
            ->where('stlg_trid', $excessId)->firstOrFail();

        $this->assertEquals($opQty, (float) $sh->stlg_opqty);
        $this->assertEquals(1.0, (float) $sh->stlg_trqty);
        $this->assertEquals($opQty - 1.0, (float) $sh->stlg_balqty);
        $this->assertEquals($opUps - 1, (int) $sh->stlg_balups);
        $this->assertEquals($opQty - 1.0, (float) StockLedgerService::balanceAt(
            (int) $item->items_id,
            (int) $ledger->stlg_whid,
            (int) $ledger->stlg_binid,
            (int) $ledger->stlg_subbinid,
            now()->toDateString()
        )['qty']);
    }

    public function test_shortage_beyond_balance_is_rejected(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $this->saveDoc($this->payload($item, 'good', [
            $this->row((int) $ledger->stlg_id, 0, 0.0, 1, (float) $ledger->stlg_balqty + 5.0),
        ]))->assertStatus(422);

        $fy = FiscalYear::yearcode();
        $this->assertSame(0, Excess::query()->where('yearcode', $fy)->count());
        $this->assertSame(0, StockLedgerGood::query()
            ->where('stlg_trtype', 'ES')
            ->where('stlg_trid', '>', 0)
            ->where('yearcode', $fy)->count());
    }

    public function test_row_cannot_carry_both_sides(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $this->saveDoc($this->payload($item, 'good', [
            $this->row((int) $ledger->stlg_id, 2, 3.0, 1, 1.0),
        ]))->assertStatus(422);

        $this->assertSame(0, Excess::query()->where('yearcode', FiscalYear::yearcode())->count());
    }

    public function test_row_must_belong_to_the_item(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $this->saveDoc($this->payload($item, 'good', [
            $this->row((int) $ledger->stlg_id + 1000000, 2, 3.0),
        ]))->assertStatus(422);

        $this->assertSame(0, Excess::query()->where('yearcode', FiscalYear::yearcode())->count());
    }

    public function test_damage_ledger_document_adjusts_damage_rows(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $opQty = (float) $ledger->stld_balqty;
        $opUps = (int) $ledger->stld_balups;

        $resp = $this->saveDoc($this->payload($item, 'damage', [
            $this->row((int) $ledger->stld_id, 0, 0.0, 1, 1.0),
        ]));
        $resp->assertOk();
        $excessId = (int) $resp->json('excess_id');

        $this->post(route('exshorts.post', Excess::query()->findOrFail($excessId)))->assertRedirect();

        $sh = StockLedgerDamage::query()
            ->where('stld_trtype', 'ES')->where('stld_trsubtype', 'SH')
            ->where('stld_trid', $excessId)->firstOrFail();

        $this->assertSame((int) $ledger->stld_whid, (int) $sh->stld_whid);
        $this->assertSame((int) $ledger->stld_subbinid, (int) $sh->stld_subbinid);
        $this->assertEquals($opQty, (float) $sh->stld_opqty);
        $this->assertEquals($opQty - 1.0, (float) $sh->stld_balqty);
        $this->assertEquals($opUps - 1, (int) $sh->stld_balups);

        // The good ledger is untouched by a 'damage' document.
        $this->assertSame(0, StockLedgerGood::query()
            ->where('stlg_trtype', 'ES')->where('stlg_trid', $excessId)->count());
    }

    public function test_mixed_rows_post_each_side_with_its_own_subtype(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        // A second positive location for the same item (seed row, swept).
        $otherLoc = $this->otherRealLocation([(int) $ledger->stlg_subbinid]);
        $seed = $this->seedGoodRow($item, $otherLoc, 1, 5.0);

        $resp = $this->saveDoc($this->payload($item, 'good', [
            $this->row((int) $ledger->stlg_id, 0, 0.0, 1, 1.0),   // shortage
            $this->row((int) $seed->stlg_id, 2, 4.0),             // excess
        ]));
        $resp->assertOk();
        $excessId = (int) $resp->json('excess_id');

        $this->post(route('exshorts.post', Excess::query()->findOrFail($excessId)))->assertRedirect();

        $rows = StockLedgerGood::query()
            ->where('stlg_trtype', 'ES')
            ->where('stlg_trid', $excessId)
            ->get();
        $this->assertSame(2, $rows->count());

        $sh = $rows->firstWhere('stlg_trsubtype', 'SH');
        $es = $rows->firstWhere('stlg_trsubtype', 'ES');
        $this->assertNotNull($sh);
        $this->assertNotNull($es);

        $this->assertEquals((float) $ledger->stlg_balqty - 1.0, (float) $sh->stlg_balqty);
        // Differential: the seed's own balance (its location may already
        // have hosted stock for this item) plus the excess.
        $this->assertEquals((float) $seed->stlg_balqty + 4.0, (float) $es->stlg_balqty);
        $this->assertSame($otherLoc['subbinid'], (int) $es->stlg_subbinid);
    }

    public function test_reorder_pass_flags_when_balance_drops_to_the_level(): void
    {
        $this->operator();
        [$source] = $this->stockedItem();
        $item = $this->srlItem($source, 5);

        // 5.0 at a fresh location; a FULL drain drops the item's summed
        // positive balance to the reorder level 5.0 (flag condition) —
        // under the legacy latest-per-location sum AND the documented
        // degenerate all-positive-rows sum alike.
        $loc = $this->otherRealLocation([]);
        $seed = $this->seedGoodRow($item, $loc, 1, 5.0);

        $resp = $this->saveDoc($this->payload($item, 'good', [
            $this->row((int) $seed->stlg_id, 0, 0.0, 1, 5.0),
        ]));
        $resp->assertOk();
        $excessId = (int) $resp->json('excess_id');

        $this->post(route('exshorts.post', Excess::query()->findOrFail($excessId)))->assertRedirect();

        // The drained item's positive rows are flagged orstatus='R'
        // (the 0-balance shortage row is not itself positive).
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_tritemid', (int) $item->items_id)
            ->where('stlg_balqty', '>', 0)
            ->where('orstatus', 'R')
            ->count());
    }

    public function test_no_subbin_status_flip_on_adjustment(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $subbinId = (int) $ledger->stlg_subbinid;
        $statusBefore = (string) SubBin::query()->where('sid', $subbinId)->value('status');

        // Full drain: balqty hits 0 — the writer families that flip sub-bin
        // status would mark it 'Empty'; the legacy ES writer does not.
        $resp = $this->saveDoc($this->payload($item, 'good', [
            $this->row((int) $ledger->stlg_id, 0, 0.0, (int) $ledger->stlg_balups, (float) $ledger->stlg_balqty),
        ]));
        $resp->assertOk();
        $excessId = (int) $resp->json('excess_id');

        $this->post(route('exshorts.post', Excess::query()->findOrFail($excessId)))->assertRedirect();

        $sh = StockLedgerGood::query()
            ->where('stlg_trtype', 'ES')->where('stlg_trsubtype', 'SH')
            ->where('stlg_trid', $excessId)->firstOrFail();
        $this->assertEquals(0.0, (float) $sh->stlg_balqty);

        $this->assertSame($statusBefore, (string) SubBin::query()->where('sid', $subbinId)->value('status'));
        $this->assertNotSame('Empty', (string) SubBin::query()->where('sid', $subbinId)->value('status'));
    }

    public function test_posted_document_is_immutable(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->saveDoc($this->payload($item, 'good', [$this->row((int) $ledger->stlg_id, 2, 3.0)]));
        $resp->assertOk();
        $excessId = (int) $resp->json('excess_id');
        $excess = Excess::query()->findOrFail($excessId);

        $this->post(route('exshorts.post', $excess))->assertRedirect();

        // Row edits are rejected on posted documents...
        $this->postJson(route('exshorts.store'), $this->payload($item, 'good', [
            $this->row((int) $ledger->stlg_id, 5, 5.0),
        ], ['excess_id' => $excessId]))->assertStatus(422);

        // ...and a re-post redirects with the already-posted error.
        $this->post(route('exshorts.post', $excess))
            ->assertRedirect(route('exshorts.show', $excess))
            ->assertSessionHas('error');

        // Idempotent: still exactly one ledger row set for the document.
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_trtype', 'ES')->where('stlg_trid', $excessId)->count());
    }

    public function test_workspace_renders_lines_and_posted_state(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->saveDoc($this->payload($item, 'good', [$this->row((int) $ledger->stlg_id, 2, 3.0)]));
        $resp->assertOk();
        $excess = Excess::query()->findOrFail((int) $resp->json('excess_id'));

        $this->get(route('exshorts.workspace', $excess))
            ->assertOk()
            ->assertSee('TES'.$excess->code)
            ->assertSee('Adjustment rows');

        $this->post(route('exshorts.post', $excess))->assertRedirect();

        $excess->refresh();

        $this->get(route('exshorts.show', $excess))
            ->assertOk()
            ->assertSee('Bin Status Sheet')
            ->assertSee('Shortage');

        $this->get(route('exshorts.index'))
            ->assertOk()
            ->assertSee(DocumentNumber::pretty('excess', (int) $excess->escode, (string) $excess->yearcode));
    }
}
