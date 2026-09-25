<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\Dtog;
use App\Models\DtogItem;
use App\Models\FinancialYear;
use App\Models\Gtod;
use App\Models\GtodItem;
use App\Models\Item;
use App\Models\PartyLedger;
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
 * Phase 9 slice 8 acceptance: the gate movement module — Good→Damage /
 * Damage→Good stock conversion (legacy getuser_gdupdate.php +
 * getuser_gdetdupdate.php + getuser_dgupdate.php line saves, add_gtod_
 * preview.php + add_dtog_preview.php posts, getuser_gd_slocshow.php +
 * getuser_dg_slocshowd.php availability, add_g.php / add_d.php queues).
 *
 * Posting semantics under test:
 *  - G2D: good out row (trtype/subtype 'GD', trid = the document id,
 *    party id = the header's party) + damage in row per destination slot
 *    with the ES-style balance (op resolved to the live latest, op + tr,
 *    UPS normalization) and subbin status → 'Damage'; on a full source
 *    drain the unconditional Empty flip (the legacy scoped check was
 *    dead code — undefined $totnog); one party-ledger row per document
 *    (damage = Σ tr, bal = opening − damage); reorder pass; gcode/ncode
 *    commit + gdflg = 1; no gate pass;
 *  - D2G: damage out row (trtype/subtype 'DG', party id 0, VERBATIM
 *    quirk: balups = op — UPS NOT decremented) + good in row per
 *    destination slot (ES-style balance, subbin status → 'Good'); NO
 *    party ledger, NO reorder pass; dcode/ncode commit + dgflg = 1.
 *
 * Hermeticity: the ledgers are append-only, so every test sweeps the gate
 * artifacts it could have created (documents, rows, GD/DG ledger rows,
 * the trid=-901/-902 seed rows this suite posts, party-ledger rows,
 * audit rows) in setUp AND tearDown — deleting the appended rows reverts
 * every item x location balance to its migrated baseline. The
 * stocked-item helpers exclude srl-tracked items so the reorder pass can
 * never permanently re-flag migrated rows (the reorder test uses a
 * suite-created item instead).
 */
class Phase9GateMovementTest extends TestCase
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

    /** Remove every test-created gate artifact (documents, rows, ledger
     * rows incl. the negative-trid seeds, party ledger rows, audit rows). */
    private function sweepArtifacts(): void
    {
        if (! Schema::hasTable('gtods') || ! Schema::hasTable('dtogs')) {
            return;
        }

        $fy = FiscalYear::yearcode();

        $testGtodIds = Gtod::query()->where('yearcode', $fy)->pluck('gid');
        $testDtogIds = Dtog::query()->where('yearcode', $fy)->pluck('did');

        StockLedgerGood::query()
            ->where(function ($q) use ($testGtodIds, $testDtogIds) {
                $q->where(function ($q1) use ($testDtogIds) {
                    $q1->where('stlg_trtype', 'DG')->whereIn('stlg_trid', $testDtogIds);
                })->orWhere(function ($q2) use ($testGtodIds) {
                    $q2->where('stlg_trtype', 'GD')->whereIn('stlg_trid', $testGtodIds);
                })->orWhere(function ($q3) {
                    // Seed rows this suite creates carry a negative trid
                    // and today's date (real posts always carry a document
                    // id) — swept so they cannot leak into other runs.
                    $q3->whereIn('stlg_trid', self::SEED_TRIDS)
                        ->where('stlg_trdate', '>=', now()->toDateString());
                });
            })->delete();

        StockLedgerDamage::query()
            ->where(function ($q) use ($testGtodIds, $testDtogIds) {
                $q->where(function ($q1) use ($testGtodIds) {
                    $q1->where('stld_trtype', 'GD')->whereIn('stld_trid', $testGtodIds);
                })->orWhere(function ($q2) use ($testDtogIds) {
                    $q2->where('stld_trtype', 'DG')->whereIn('stld_trid', $testDtogIds);
                })->orWhere(function ($q3) {
                    $q3->whereIn('stld_trid', self::SEED_TRIDS)
                        ->where('stld_trdate', '>=', now()->toDateString());
                });
            })->delete();

        PartyLedger::query()
            ->where('pldg_trtype', 'GD')
            ->whereIn('pldg_trid', $testGtodIds)
            ->where('yearcode', $fy)
            ->delete();

        GtodItem::query()->whereIn('gid', $testGtodIds)->delete();
        Gtod::query()->whereIn('gid', $testGtodIds)->delete();
        DtogItem::query()->whereIn('did', $testDtogIds)->delete();
        Dtog::query()->whereIn('did', $testDtogIds)->delete();
        DB::table('audit_logs')->where('module', 'like', 'gatemovement.%')->delete();
    }

    private function operator(): User
    {
        $user = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /**
     * An active, NOT srl-tracked item holding a positive GOOD-ledger row
     * that is the latest for its location (getuser_gd_slocshow
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
            // Real rows always carry a document id — exclude other suites'
            // unswept seed debris (trid <= 0).
            ->where('stock_ledger_goods.stlg_trid', '>', 0)
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
            // Real rows always carry a document id — exclude other suites'
            // unswept seed debris (trid <= 0).
            ->where('stock_ledger_damages.stld_trid', '>', 0)
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

    /** Seed a positive good-ledger row for the item at the location via
     * the service (a trid=-901 row — swept by the harness). Legacy seed
     * trtype/subtype; the negative trid marks it as test data. */
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

    /** Seed a positive damage-ledger row for the item at the location
     * (a trid=-902 row — swept by the harness). */
    private function seedDamageRow(Item $item, array $loc, int $ups, float $qty, int $trid = -902): StockLedgerDamage
    {
        return StockLedgerService::post([
            'direction' => 'in',
            'damage' => true,
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
        $item->stores_item = 'TEST-GM-COMPANION-'.uniqid();
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
        $item->stores_item = 'TEST-GM-SRL-'.uniqid();
        $item->uom = $source->uom;
        $item->actstatus = 'Active';
        $item->srl = $level;
        $item->srl_status = 'Yes';
        $item->save();

        return $item;
    }

    /** The first party (the header's G2D party). */
    private function party(): int
    {
        return (int) DB::table('parties')->orderBy('p_id')->value('p_id');
    }

    private function row(int $rowid, array $dest, int $ups, float $qty): array
    {
        return [
            'rowid' => $rowid,
            'whid' => $dest['whid'],
            'binid' => $dest['binid'],
            'subbinid' => $dest['subbinid'],
            'ups' => $ups,
            'qty' => $qty,
        ];
    }

    private function payload(Item $item, string $direction, array $rows, array $over = []): array
    {
        return array_merge([
            'direction' => $direction,
            'gtod_id' => null,
            'dtog_id' => null,
            'tdate' => now()->toDateString(),
            'classification_id' => (int) $item->classification_id,
            'items_id' => (int) $item->items_id,
            'uom' => (string) $item->uom,
            'party_id' => $direction === 'g2d' ? $this->party() : 0,
            'remarks' => 'Phase 9 slice 8 test',
            'rows' => $rows,
        ], $over);
    }

    private function saveDoc(array $payload): TestResponse
    {
        return $this->postJson(route('gatemovements.store'), $payload);
    }

    public function test_module_requires_authentication(): void
    {
        $this->get(route('gatemovements.index'))->assertRedirect(route('login'));
        $this->postJson(route('gatemovements.store'), [])->assertStatus(401);
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
            $this->get(route('gatemovements.index'))->assertStatus(500);
        } finally {
            $fy->years_status = 'a';
            $fy->save();
            FiscalYear::invalidateForTesting();
        }

        $this->get(route('gatemovements.index'))->assertOk();
    }

    public function test_availability_endpoint_returns_source_rows(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->getJson(route('gatemovements.availability', $item->items_id).'?direction=g2d');
        $resp->assertOk()->assertJsonPath('ok', true);

        $rowids = collect($resp->json('rows'))->pluck('rowid')->map(fn ($v) => (int) $v);
        $this->assertContains((int) $ledger->stlg_id, $rowids);

        // The listed row carries the opening balances the post will use.
        $row = collect($resp->json('rows'))->firstWhere('rowid', (int) $ledger->stlg_id);
        $this->assertEquals((float) $ledger->stlg_balqty, (float) $row['qty']);
    }

    public function test_g2d_moves_good_stock_into_the_damage_ledger(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $opQty = (float) $ledger->stlg_balqty;
        $opUps = (int) $ledger->stlg_balups;

        $dest = $this->otherRealLocation([(int) $ledger->stlg_subbinid]);

        $resp = $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id, $dest, 2, 3.0),
        ]));
        $resp->assertOk();
        $gid = (int) $resp->json('gtod_id');

        $gtod = Gtod::query()->findOrFail($gid);
        $this->assertSame(0, (int) $gtod->gdflg);
        $this->assertGreaterThan(0, (int) $gtod->code);
        $this->assertSame($this->party(), (int) $gtod->party_id);

        // Saved row: destination slot + the referenced source row.
        $line = GtodItem::query()->where('gid', $gid)->firstOrFail();
        $this->assertSame((int) $ledger->stlg_id, (int) $line->rowid);
        $this->assertSame($dest['subbinid'], (int) $line->subbinid);
        // ES-style destination bookkeeping (qty 3 > 0, ups 2 > 0).
        $this->assertSame(2, (int) $line->balups);
        $this->assertEquals(3.0, (float) $line->balqty);

        // Differential counter assertion (counters persist in the shared
        // DB, so prime them to known values first).
        $fy = FiscalYear::yearcode();
        DocumentNumber::prime('gtod', $fy, 500);
        DocumentNumber::prime('gtod.n', $fy, 700);

        $this->post(route('gatemovements.post', $gtod))->assertRedirect();

        // Good out row: trtype 'GD', party id on the row, full drain of
        // 3.0 leaves op − tr (UPS op − 2, clamped by the service).
        $out = StockLedgerGood::query()
            ->where('stlg_trtype', 'GD')->where('stlg_trsubtype', 'GD')
            ->where('stlg_trid', $gid)->firstOrFail();
        $this->assertSame((int) $ledger->stlg_whid, (int) $out->stlg_whid);
        $this->assertSame((int) $ledger->stlg_subbinid, (int) $out->stlg_subbinid);
        $this->assertSame((int) $item->items_id, (int) $out->stlg_tritemid);
        $this->assertEquals($opQty, (float) $out->stlg_opqty);
        $this->assertEquals(3.0, (float) $out->stlg_trqty);
        $this->assertEquals($opQty - 3.0, (float) $out->stlg_balqty);
        // Legacy writer's UPS normalization: balqty > 0 && balups == 0 → 1
        // (opUps − 2 hits 0 while the qty balance stays positive).
        $this->assertEquals($opQty - 3.0 > 0 && $opUps - 2 === 0 ? 1 : $opUps - 2, (int) $out->stlg_balups);
        $this->assertSame($this->party(), (int) $out->stlg_trpartyid);

        // Damage in row: ES-style balance from the live destination open.
        $in = StockLedgerDamage::query()
            ->where('stld_trtype', 'GD')->where('stld_trsubtype', 'GD')
            ->where('stld_trid', $gid)->firstOrFail();
        $this->assertSame($dest['subbinid'], (int) $in->stld_subbinid);
        $this->assertEquals(3.0, (float) $in->stld_trqty);
        $this->assertEquals(3.0, (float) $in->stld_balqty);
        $this->assertEquals(2, (int) $in->stld_balups);
        $this->assertSame($this->party(), (int) $in->stld_trpartyid);

        // Party ledger: ONE row per document — damage = Σ tr, bal = open − damage.
        $pl = PartyLedger::query()
            ->where('pldg_trtype', 'GD')->where('pldg_trid', $gid)->firstOrFail();
        $this->assertSame($this->party(), (int) $pl->pldg_trpartyid);
        $this->assertEquals(3.0, (float) $pl->pldg_trdamageqty);
        $this->assertEquals(2, (int) $pl->pldg_trdamageups);
        $this->assertEquals(-3.0, (float) $pl->pldg_trbalqty);
        $this->assertEquals(-2, (int) $pl->pldg_trbalups);
        // Legacy wrote every other side as 0 (dc/good/ex/sh).
        $this->assertSame(0, (int) $pl->pldg_trdcups);
        $this->assertEquals(0.0, (float) $pl->pldg_trdcqty);
        $this->assertSame(0, (int) $pl->pldg_trgoodups);
        $this->assertEquals(0.0, (float) $pl->pldg_trexqty);
        $this->assertEquals(0.0, (float) $pl->pldg_trshqty);

        // Committed serials (differential) + posted marker.
        $gtod->refresh();
        $this->assertSame(1, (int) $gtod->gdflg);
        $this->assertSame(501, (int) $gtod->gcode);
        $this->assertSame(701, (int) $gtod->ncode);

        // Live balances follow the ledgers.
        $this->assertEquals($opQty - 3.0, (float) StockLedgerService::balanceAt(
            (int) $item->items_id,
            (int) $ledger->stlg_whid,
            (int) $ledger->stlg_binid,
            (int) $ledger->stlg_subbinid,
            now()->toDateString()
        )['qty']);
        $this->assertEquals(3.0, (float) StockLedgerService::damageBalanceAt(
            (int) $item->items_id,
            $dest['whid'],
            $dest['binid'],
            $dest['subbinid'],
            now()->toDateString()
        )['qty']);
    }

    public function test_g2d_draining_the_source_flips_the_subbin_empty(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $subbinId = (int) $ledger->stlg_subbinid;
        $dest = $this->otherRealLocation([$subbinId]);

        $resp = $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id, $dest, (int) $ledger->stlg_balups, (float) $ledger->stlg_balqty),
        ]));
        $resp->assertOk();
        $gid = (int) $resp->json('gtod_id');

        $this->post(route('gatemovements.post', Gtod::query()->findOrFail($gid)))->assertRedirect();

        // Full drain: balqty hits 0 — the GD writer's Empty flip applies
        // (the legacy scoped cross-item check was dead code).
        $out = StockLedgerGood::query()
            ->where('stlg_trtype', 'GD')->where('stlg_trid', $gid)->firstOrFail();
        $this->assertEquals(0.0, (float) $out->stlg_balqty);

        $this->assertSame('Empty', (string) SubBin::query()->where('sid', $subbinId)->value('status'));

        // The destination flips to Damage (legacy GD writer, unconditional).
        $this->assertSame('Damage', (string) SubBin::query()->where('sid', $dest['subbinid'])->value('status'));
    }

    public function test_d2g_moves_damage_stock_into_the_good_ledger(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $opQty = (float) $ledger->stld_balqty;
        $opUps = (int) $ledger->stld_balups;

        $dest = $this->otherRealLocation([(int) $ledger->stld_subbinid]);

        $resp = $this->saveDoc($this->payload($item, 'd2g', [
            $this->row((int) $ledger->stld_id, $dest, 1, 2.0),
        ]));
        $resp->assertOk();
        $did = (int) $resp->json('dtog_id');

        $dtog = Dtog::query()->findOrFail($did);
        $this->assertSame(0, (int) $dtog->dgflg);

        // Differential counter assertion.
        $fy = FiscalYear::yearcode();
        DocumentNumber::prime('dtog', $fy, 300);
        DocumentNumber::prime('dtog.n', $fy, 400);

        $this->post(route('gatemovements.post-d2g', $dtog))->assertRedirect();

        // Damage out row: trtype 'DG', party id 0 — and the VERBATIM
        // quirk: balups = op (UPS NOT decremented).
        $out = StockLedgerDamage::query()
            ->where('stld_trtype', 'DG')->where('stld_trsubtype', 'DG')
            ->where('stld_trid', $did)->firstOrFail();
        $this->assertSame((int) $ledger->stld_whid, (int) $out->stld_whid);
        $this->assertSame((int) $ledger->stld_subbinid, (int) $out->stld_subbinid);
        $this->assertSame((int) $item->items_id, (int) $out->stld_tritemid);
        $this->assertEquals($opQty, (float) $out->stld_opqty);
        $this->assertEquals(2.0, (float) $out->stld_trqty);
        $this->assertEquals($opQty - 2.0, (float) $out->stld_balqty);
        // The quirk itself: stld_balups stays at the opening UPS.
        $this->assertEquals($opUps, (int) $out->stld_balups);
        $this->assertSame(0, (int) $out->stld_trpartyid);

        // Good in row: ES-style balance.
        $in = StockLedgerGood::query()
            ->where('stlg_trtype', 'DG')->where('stlg_trsubtype', 'DG')
            ->where('stlg_trid', $did)->firstOrFail();
        $this->assertSame($dest['subbinid'], (int) $in->stlg_subbinid);
        $this->assertEquals(2.0, (float) $in->stlg_trqty);
        $this->assertEquals(2.0, (float) $in->stlg_balqty);
        $this->assertEquals(1, (int) $in->stlg_balups);
        $this->assertSame(0, (int) $in->stlg_trpartyid);

        // D2G posts NO party ledger (the legacy DG writer has none).
        $this->assertSame(0, PartyLedger::query()
            ->where('pldg_trtype', 'DG')->where('pldg_trid', $did)->count());

        // Committed serials (differential) + posted marker.
        $dtog->refresh();
        $this->assertSame(1, (int) $dtog->dgflg);
        $this->assertSame(301, (int) $dtog->dcode);
        $this->assertSame(401, (int) $dtog->ncode);

        // Live balances follow the ledgers.
        $this->assertEquals($opQty - 2.0, (float) StockLedgerService::damageBalanceAt(
            (int) $item->items_id,
            (int) $ledger->stld_whid,
            (int) $ledger->stld_binid,
            (int) $ledger->stld_subbinid,
            now()->toDateString()
        )['qty']);
        $this->assertEquals(2.0, (float) StockLedgerService::balanceAt(
            (int) $item->items_id,
            $dest['whid'],
            $dest['binid'],
            $dest['subbinid'],
            now()->toDateString()
        )['qty']);
    }

    public function test_d2g_draining_the_source_flips_the_subbin_empty(): void
    {
        $this->operator();
        [$item, $ledger] = $this->damagedItem();

        $subbinId = (int) $ledger->stld_subbinid;
        $dest = $this->otherRealLocation([$subbinId]);

        $resp = $this->saveDoc($this->payload($item, 'd2g', [
            $this->row((int) $ledger->stld_id, $dest, (int) $ledger->stld_balups, (float) $ledger->stld_balqty),
        ]));
        $resp->assertOk();
        $did = (int) $resp->json('dtog_id');

        $this->post(route('gatemovements.post-d2g', Dtog::query()->findOrFail($did)))->assertRedirect();

        $out = StockLedgerDamage::query()
            ->where('stld_trtype', 'DG')->where('stld_trid', $did)->firstOrFail();
        $this->assertEquals(0.0, (float) $out->stld_balqty);

        $this->assertSame('Empty', (string) SubBin::query()->where('sid', $subbinId)->value('status'));
        $this->assertSame('Good', (string) SubBin::query()->where('sid', $dest['subbinid'])->value('status'));
    }

    public function test_conversion_beyond_balance_is_rejected(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $dest = $this->otherRealLocation([(int) $ledger->stlg_subbinid]);

        $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id, $dest, 1, (float) $ledger->stlg_balqty + 5.0),
        ]))->assertStatus(422);

        $fy = FiscalYear::yearcode();
        $this->assertSame(0, Gtod::query()->where('yearcode', $fy)->count());
        $this->assertSame(0, StockLedgerGood::query()
            ->where('stlg_trtype', 'GD')
            ->where('stlg_trid', '>', 0)
            ->where('yearcode', $fy)->count());
    }

    public function test_row_must_belong_to_the_item(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $dest = $this->otherRealLocation([(int) $ledger->stlg_subbinid]);

        $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id + 1000000, $dest, 2, 3.0),
        ]))->assertStatus(422);

        $this->assertSame(0, Gtod::query()->where('yearcode', FiscalYear::yearcode())->count());
    }

    public function test_destination_must_be_a_real_subbin(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id, ['whid' => 1, 'binid' => 999999, 'subbinid' => 999999], 2, 3.0),
        ]))->assertStatus(422);

        $this->assertSame(0, Gtod::query()->where('yearcode', FiscalYear::yearcode())->count());
    }

    public function test_g2d_requires_a_party(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $dest = $this->otherRealLocation([(int) $ledger->stlg_subbinid]);

        $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id, $dest, 2, 3.0),
        ], ['party_id' => 0]))->assertStatus(422);

        $this->assertSame(0, Gtod::query()->where('yearcode', FiscalYear::yearcode())->count());
    }

    public function test_multiple_destinations_per_source_post_one_out_row(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $dest1 = $this->otherRealLocation([(int) $ledger->stlg_subbinid]);
        $dest2 = $this->otherRealLocation([(int) $ledger->stlg_subbinid, $dest1['subbinid']]);

        $resp = $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id, $dest1, 1, 1.0),
            $this->row((int) $ledger->stlg_id, $dest2, 1, 2.0),
        ]));
        $resp->assertOk();
        $gid = (int) $resp->json('gtod_id');

        $this->post(route('gatemovements.post', Gtod::query()->findOrFail($gid)))->assertRedirect();

        // ONE out row per rowid group (legacy GROUP BY rowid), tr = Σ.
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_trtype', 'GD')->where('stlg_trid', $gid)->count());
        $out = StockLedgerGood::query()
            ->where('stlg_trtype', 'GD')->where('stlg_trid', $gid)->firstOrFail();
        $this->assertEquals(3.0, (float) $out->stlg_trqty);
        $this->assertEquals(2, (int) $out->stlg_trups);

        // ONE in row per destination slot.
        $this->assertSame(2, StockLedgerDamage::query()
            ->where('stld_trtype', 'GD')->where('stld_trid', $gid)->count());
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

        $dest = $this->otherRealLocation([$loc['subbinid']]);

        $resp = $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $seed->stlg_id, $dest, 1, 5.0),
        ]));
        $resp->assertOk();
        $gid = (int) $resp->json('gtod_id');

        $this->post(route('gatemovements.post', Gtod::query()->findOrFail($gid)))->assertRedirect();

        // The drained item's positive rows are flagged orstatus='R'
        // (the 0-balance out row is not itself positive).
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_tritemid', (int) $item->items_id)
            ->where('stlg_balqty', '>', 0)
            ->where('orstatus', 'R')
            ->count());
    }

    public function test_posted_document_is_immutable(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $dest = $this->otherRealLocation([(int) $ledger->stlg_subbinid]);

        $resp = $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id, $dest, 2, 3.0),
        ]));
        $resp->assertOk();
        $gid = (int) $resp->json('gtod_id');
        $gtod = Gtod::query()->findOrFail($gid);

        $this->post(route('gatemovements.post', $gtod))->assertRedirect();

        // Row edits are rejected on posted documents...
        $this->postJson(route('gatemovements.store'), $this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id, $dest, 5, 5.0),
        ], ['gtod_id' => $gid]))->assertStatus(422);

        // ...and a re-post redirects with the already-posted error.
        $this->post(route('gatemovements.post', $gtod))
            ->assertRedirect(route('gatemovements.show', $gtod))
            ->assertSessionHas('error');

        // Idempotent: still exactly one ledger row set for the document.
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_trtype', 'GD')->where('stlg_trid', $gid)->count());
        $this->assertSame(1, StockLedgerDamage::query()
            ->where('stld_trtype', 'GD')->where('stld_trid', $gid)->count());
        $this->assertSame(1, PartyLedger::query()
            ->where('pldg_trtype', 'GD')->where('pldg_trid', $gid)->count());
    }

    public function test_workspaces_and_queues_render(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $dest = $this->otherRealLocation([(int) $ledger->stlg_subbinid]);

        $resp = $this->saveDoc($this->payload($item, 'g2d', [
            $this->row((int) $ledger->stlg_id, $dest, 2, 3.0),
        ]));
        $resp->assertOk();
        $gtod = Gtod::query()->findOrFail((int) $resp->json('gtod_id'));

        $this->get(route('gatemovements.workspace', $gtod))
            ->assertOk()
            ->assertSee('TGD'.$gtod->code)
            ->assertSee('Conversion rows');

        $this->get(route('gatemovements.create'))
            ->assertOk()
            ->assertSee('New Good → Damage');

        $this->get(route('gatemovements.create-d2g'))
            ->assertOk()
            ->assertSee('New Damage → Good');

        $this->get(route('gatemovements.index-d2g'))
            ->assertOk()
            ->assertSee('Damage → Good');

        $this->post(route('gatemovements.post', $gtod))->assertRedirect();

        $gtod->refresh();

        $this->get(route('gatemovements.show', $gtod))
            ->assertOk()
            ->assertSee('Conversion rows')
            ->assertSee('Good → Damage');

        $this->get(route('gatemovements.index'))
            ->assertOk()
            ->assertSee(DocumentNumber::pretty('gtod', (int) $gtod->gcode, (string) $gtod->yearcode));
    }
}
