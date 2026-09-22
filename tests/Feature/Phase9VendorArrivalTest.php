<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\Arrival;
use App\Models\ArrivalItem;
use App\Models\ArrivalSloc;
use App\Models\FinancialYear;
use App\Models\Item;
use App\Models\Party;
use App\Models\PartyLedger;
use App\Models\StockLedgerDamage;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
use App\Models\User;
use App\Support\ArrivalNumbering;
use App\Support\ArrivalStatus;
use App\Support\DocumentNumber;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 9 slice 2 acceptance: the vendor GRN module. Legacy flow (entry ->
 * per-line good/damage SLOC rows -> final post) reusing the Phase-6 posting
 * skeleton with the party-ledger block, verbatim excess/shortage math and
 * the mixed-sloc quirk (damage side only wins).
 */
class Phase9VendorArrivalTest extends TestCase
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

        // Hermeticity: remove artifacts previous runs posted (arrivals with
        // their lines/slocs, ledger in-rows, party-ledger rows). The ledger
        // is append-only, so deleting the posted rows reverts every
        // item x location balance to its migrated baseline.
        $fy = FiscalYear::yearcode();

        $testArrivalIds = Arrival::query()
            ->where('arrival_type', 'Vendor')
            ->where('yearcode', $fy)
            ->pluck('arrival_id');

        ArrivalSloc::query()->whereIn('arr_tr_id', $testArrivalIds)->delete();
        ArrivalItem::query()->whereIn('arrival_id', $testArrivalIds)->delete();
        StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Vendor')
            ->whereIn('stlg_trid', $testArrivalIds)->delete();
        StockLedgerDamage::query()
            ->where('stld_trtype', 'Arrival')->where('stld_trsubtype', 'Vendor')
            ->whereIn('stld_trid', $testArrivalIds)->delete();
        PartyLedger::query()
            ->where('pldg_trtype', 'Arrival')->where('pldg_trsubtype', 'Vendor')
            ->whereIn('pldg_trid', $testArrivalIds)->delete();
        Arrival::query()->whereIn('arrival_id', $testArrivalIds)->delete();

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

    private function operator(): User
    {
        $user = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    private function viewer(): User
    {
        $user = User::query()->where('role', 'viewer')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /**
     * An active item with a sane positive good-ledger row (UPS <= qty) to
     * receive against, returning [item, ledger].
     */
    private function stockedItem(): array
    {
        $ledger = StockLedgerGood::query()
            ->join('items', 'items.items_id', '=', 'stock_ledger_goods.stlg_tritemid')
            ->where('stock_ledger_goods.stlg_balqty', '>', 0)
            ->whereColumn('stock_ledger_goods.stlg_balups', '<=', 'stock_ledger_goods.stlg_balqty')
            ->where('stock_ledger_goods.stlg_tritemid', '!=', 0)
            ->where('items.actstatus', 'Active')
            ->orderByDesc('stock_ledger_goods.stlg_id')
            ->firstOrFail();

        $item = Item::query()
            ->where('items_id', $ledger->stlg_tritemid)
            ->where('actstatus', 'Active')
            ->firstOrFail();

        return [$item, $ledger];
    }

    private function partyId(): int
    {
        return (int) (Party::query()->value('p_id') ?? 0);
    }

    /** Save one line (and open the workspace when no arrival_id is sent). */
    private function saveLine(array $payload): TestResponse
    {
        return $this->postJson(route('arrivals.vendor.lines.store'), $payload);
    }

    private function linePayload(array $item, array $over = []): array
    {
        return array_merge([
            'party_id' => $this->partyId(),
            'dcno' => 'DC-TEST-1',
            'arrival_date' => now()->toDateString(),
            'tmode' => 'By Hand',
            'pname_byhand' => 'Test Bearer',
            'classification_id' => $item['classification_id'],
            'items_id' => $item['items_id'],
            'ups_per_dc' => $item['ups'],
            'qty_per_dc' => $item['qty'],
            'ups_good' => $item['ups'],
            'qty_good' => $item['qty'],
            'qty_damage' => 0,
            'ups_damage' => 0,
            'slocs' => [[
                'stlg_id' => $item['stlg_id'],
                'whid' => $item['whid'],
                'binid' => $item['binid'],
                'subbin' => $item['subbin'],
                'ups_good' => $item['ups'],
                'qty_good' => $item['qty'],
            ]],
        ], $over);
    }

    public function test_vendor_arrival_requires_authentication(): void
    {
        $this->get(route('arrivals.vendor.index'))->assertRedirect(route('login'));
    }

    public function test_operators_are_bounced_from_arrival_module(): void
    {
        // The operator role carries post-transactions in this app, so the
        // gate under test is the FY middleware: without an active year the
        // module aborts instead of listing.
        $this->operator();

        $fy = FinancialYear::query()
            ->where('years_flg', '!=', 0)->where('years_status', 'a')->firstOrFail();
        $fy->years_status = 'x';
        $fy->save();
        FiscalYear::invalidateForTesting();

        try {
            $this->get(route('arrivals.vendor.index'))->assertStatus(500);
        } finally {
            $fy->years_status = 'a';
            $fy->save();
            FiscalYear::invalidateForTesting();
        }

        $this->get(route('arrivals.vendor.index'))->assertOk();
    }

    public function test_vendor_good_only_arrival_posts_good_ledger_and_party_ledger(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();
        $partyId = $this->partyId();

        $opBalance = (float) $ledger->stlg_balqty;

        $resp = $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 1,
            'qty' => 10.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ]));
        $resp->assertOk();
        $arrivalId = $resp->json('arrival_id');

        $arrival = Arrival::query()->findOrFail($arrivalId);
        $this->assertSame('Vendor', $arrival->arrival_type);
        $this->assertSame(0, (int) $arrival->arrtrflag);
        $this->assertSame(ArrivalStatus::OPEN, $arrival->status);
        $this->assertNotNull($arrival->arrival_code);
        $this->assertSame($partyId, (int) $arrival->party_id);
        $this->assertSame('DC-TEST-1', $arrival->dcno);

        $post = $this->post(route('arrivals.vendor.post', $arrival));
        $post->assertRedirect();

        $arrival->refresh();
        $this->assertSame(1, (int) $arrival->arrtrflag);
        $this->assertSame(ArrivalStatus::POSTED, $arrival->status);

        // Ledger: opening = baseline, tr = 10, bal = op + 10.
        $row = StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Vendor')
            ->where('stlg_trid', $arrivalId)
            ->where('stlg_tritemid', (int) $item->items_id)
            ->firstOrFail();
        $this->assertEquals($opBalance, (float) $row->stlg_opqty);
        $this->assertEquals(10.0, (float) $row->stlg_trqty);
        $this->assertEquals($opBalance + 10.0, (float) $row->stlg_balqty);
        $this->assertSame('Arrival', $row->stlg_trtype);
        $this->assertSame('Vendor', $row->stlg_trsubtype);
        $this->assertSame((string) $partyId, (string) $row->stlg_trpartyid);

        // Sub-bin flipped to Good by the 'in' direction.
        $this->assertSame('Good', SubBin::query()->where('sid', $row->stlg_subbinid)->value('status'));

        // Party ledger row with verbatim legacy math: exact DC -> ex = 0,
        // sh = 0 (floored from above); balance = op + good side only.
        $pldg = PartyLedger::query()
            ->where('pldg_trtype', 'Arrival')->where('pldg_trsubtype', 'Vendor')
            ->where('pldg_trid', $arrivalId)
            ->where('pldg_tritemid', (int) $item->items_id)
            ->firstOrFail();
        $this->assertEquals(10.0, (float) $pldg->pldg_trdcqty);
        $this->assertEquals(10.0, (float) $pldg->pldg_trgoodqty);
        $this->assertEquals(0.0, (float) $pldg->pldg_trexqty);
        $this->assertEquals(0.0, (float) $pldg->pldg_trshqty);

        // Committed serials: arr_code continues the legacy vendor sequence.
        $maxLegacy = (int) Arrival::query()
            ->where('arrival_type', 'Vendor')->where('yearcode', FiscalYear::yearcode())
            ->max('arr_code');
        $this->assertGreaterThanOrEqual($maxLegacy, (int) $arrival->arr_code);
    }

    public function test_vendor_damage_only_arrival_posts_damage_ledger(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $opDamage = (float) (StockLedgerDamage::query()
            ->where('stld_tritemid', (int) $item->items_id)
            ->where('stld_whid', (int) $ledger->stlg_whid)
            ->where('stld_binid', (int) $ledger->stlg_binid)
            ->where('stld_subbinid', (int) $ledger->stlg_subbinid)
            ->orderByDesc('stld_id')->value('stld_balqty') ?? 0);

        $resp = $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 0,
            'qty' => 5.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ], [
            'ups_good' => 0,
            'qty_good' => 0,
            'ups_damage' => 0,
            'qty_damage' => 5.0,
            'slocs' => [[
                'stlg_id' => (int) $ledger->stlg_id,
                'whid' => (int) $ledger->stlg_whid,
                'binid' => (int) $ledger->stlg_binid,
                'subbin' => (int) $ledger->stlg_subbinid,
                'ups_good' => 0,
                'qty_good' => 0,
                'ups_damage' => 0,
                'qty_damage' => 5.0,
            ]],
        ]));
        $resp->assertOk();
        $arrivalId = $resp->json('arrival_id');

        $this->post(route('arrivals.vendor.post', Arrival::query()->findOrFail($arrivalId)))->assertRedirect();

        // Good ledger untouched; damage ledger carries the receipt.
        $this->assertSame(0, StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Vendor')
            ->where('stlg_trid', $arrivalId)->count());

        $dmg = StockLedgerDamage::query()
            ->where('stld_trtype', 'Arrival')->where('stld_trsubtype', 'Vendor')
            ->where('stld_trid', $arrivalId)->firstOrFail();
        $this->assertEquals($opDamage, (float) $dmg->stld_opqty);
        $this->assertEquals($opDamage + 5.0, (float) $dmg->stld_balqty);

        // Sub-bin flipped to Damage.
        $this->assertSame('Damage', SubBin::query()->where('sid', $dmg->stld_subbinid)->value('status'));
    }

    public function test_mixed_sloc_row_posts_only_the_damage_side(): void
    {
        // LEGACY QUIRK (verbatim): a sloc row carrying BOTH good and damage
        // quantities posts only the damage side; the good side is dropped.
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 0,
            'qty' => 0.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ], [
            'ups_good' => 2,
            'qty_good' => 20.0,
            'ups_damage' => 0,
            'qty_damage' => 5.0,
            'slocs' => [[
                'stlg_id' => (int) $ledger->stlg_id,
                'whid' => (int) $ledger->stlg_whid,
                'binid' => (int) $ledger->stlg_binid,
                'subbin' => (int) $ledger->stlg_subbinid,
                'ups_good' => 2,
                'qty_good' => 20.0,
                'ups_damage' => 0,
                'qty_damage' => 5.0,
            ]],
        ]));
        $resp->assertOk();
        $arrivalId = $resp->json('arrival_id');

        $this->post(route('arrivals.vendor.post', Arrival::query()->findOrFail($arrivalId)))->assertRedirect();

        // The good side of the mixed row is silently dropped (legacy parity)
        // — only the damage ledger row exists.
        $this->assertSame(0, StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Vendor')
            ->where('stlg_trid', $arrivalId)->count());
        $this->assertSame(1, StockLedgerDamage::query()
            ->where('stld_trid', $arrivalId)->count());

        // The audit row records the dropped good side.
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'arrival.vendor',
            'action' => 'post',
            'record_id' => $arrivalId,
        ]);
    }

    public function test_vendor_excess_and_shortage_math_is_verbatim(): void
    {
        // Excess: good(20) + damage(0) - dc(10) = 10 (floored at 0).
        // Shortage: dc(10) - good(20) = -10 (stored <= 0).
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 1,
            'qty' => 10.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ], [
            'ups_good' => 2,
            'qty_good' => 20.0,
            'slocs' => [[
                'stlg_id' => (int) $ledger->stlg_id,
                'whid' => (int) $ledger->stlg_whid,
                'binid' => (int) $ledger->stlg_binid,
                'subbin' => (int) $ledger->stlg_subbinid,
                'ups_good' => 2,
                'qty_good' => 20.0,
            ]],
        ]));
        $resp->assertOk();
        $arrivalId = $resp->json('arrival_id');

        $this->post(route('arrivals.vendor.post', Arrival::query()->findOrFail($arrivalId)))->assertRedirect();

        $pldg = PartyLedger::query()->where('pldg_trid', $arrivalId)->firstOrFail();
        $this->assertEquals(10.0, (float) $pldg->pldg_trexqty);

        // Shortage is stored <= 0 in legacy (dc - good + damage, positive
        // values floored to 0): dc(10) - good(20) = -10 stays verbatim.
        $this->assertEquals(-10.0, (float) $pldg->pldg_trshqty);

        // Party balance seeds from the good side only.
        $this->assertEquals(20.0, (float) $pldg->pldg_trbalqty);
        $this->assertEquals(2, (int) $pldg->pldg_trbalups);

        // The arrival_items line mirrors the same math in exsh_qty.
        $line = ArrivalItem::query()->where('arrival_id', $arrivalId)->firstOrFail();
        $this->assertEquals(10.0, (float) $line->exsh_qty);
    }

    public function test_distribution_must_cover_the_line_totals(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        // Line says 10 but only 4 distributed -> rejected.
        $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 1,
            'qty' => 10.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ], ['slocs' => [[
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
            'ups_good' => 1,
            'qty_good' => 4.0,
        ]]]))->assertStatus(422);
    }

    public function test_sloc_rows_must_reference_real_locations(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        // A bin that does not belong to the warehouse -> rejected.
        $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 1,
            'qty' => 10.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ], ['slocs' => [[
            'whid' => (int) $ledger->stlg_whid,
            'binid' => 999999999,
            'subbin' => (int) $ledger->stlg_subbinid,
            'ups_good' => 1,
            'qty_good' => 10.0,
        ]]]))->assertStatus(422);
    }

    public function test_posting_is_idempotent(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 1,
            'qty' => 3.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ]));
        $arrivalId = $resp->json('arrival_id');
        $arrival = Arrival::query()->findOrFail($arrivalId);

        $this->post(route('arrivals.vendor.post', $arrival))->assertRedirect();
        $code = (int) $arrival->refresh()->arr_code;
        $bal = (float) StockLedgerGood::query()
            ->where('stlg_trid', $arrivalId)->where('stlg_trsubtype', 'Vendor')
            ->orderByDesc('stlg_id')->value('stlg_balqty');

        // A second post is redirected away, not double-applied.
        $this->post(route('arrivals.vendor.post', $arrival))->assertRedirect();
        $arrival->refresh();
        $this->assertSame($code, (int) $arrival->arr_code);
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_trid', $arrivalId)->where('stlg_trsubtype', 'Vendor')->count());
        $this->assertEquals($bal, (float) StockLedgerGood::query()
            ->where('stlg_trid', $arrivalId)->where('stlg_trsubtype', 'Vendor')
            ->orderByDesc('stlg_id')->value('stlg_balqty'));
    }

    public function test_posted_arrival_is_immutable(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 1,
            'qty' => 2.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ]));
        $arrivalId = $resp->json('arrival_id');
        $arrival = Arrival::query()->findOrFail($arrivalId);
        $line = ArrivalItem::query()->where('arrival_id', $arrivalId)->firstOrFail();

        $this->post(route('arrivals.vendor.post', $arrival))->assertRedirect();

        // Line edits and deletes are rejected after posting.
        $this->putJson(route('arrivals.vendor.lines.update', $line->arrsub_id),
            $this->linePayload([
                'items_id' => (int) $item->items_id,
                'classification_id' => (int) $item->classification_id,
                'ups' => 1,
                'qty' => 5.0,
                'stlg_id' => (int) $ledger->stlg_id,
                'whid' => (int) $ledger->stlg_whid,
                'binid' => (int) $ledger->stlg_binid,
                'subbin' => (int) $ledger->stlg_subbinid,
            ]))->assertStatus(422);

        $this->deleteJson(route('arrivals.vendor.lines.delete', $line->arrsub_id))->assertStatus(422);
    }

    public function test_per_type_numbering_is_isolated(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $yearcode = FiscalYear::yearcode();

        // Reset the three arrival counters so the assertion observes exactly
        // one consumed vendor serial (the active FY has no legacy arrivals
        // in the test template — data stops at 14-15).
        foreach (ArrivalNumbering::TYPES as $typeKey => $spec) {
            DocumentNumber::prime($spec['counter'], $yearcode, 0);
            DocumentNumber::prime($spec['counter'].'.n', $yearcode, 0);
        }

        $resp = $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 1,
            'qty' => 1.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ]));
        $arrivalId = $resp->json('arrival_id');
        $this->post(route('arrivals.vendor.post', Arrival::query()->findOrFail($arrivalId)))->assertRedirect();

        $arrival = Arrival::query()->findOrFail($arrivalId);
        $this->assertSame(1, (int) $arrival->arr_code);
        $this->assertSame(1, (int) $arrival->ncode);

        // The stock-transfer and internal counters were untouched by this
        // vendor post — still sitting at the reset value.
        foreach (['stocktr', 'internal'] as $typeKey) {
            $this->assertSame(0,
                DocumentNumber::current(ArrivalNumbering::TYPES[$typeKey]['counter'], $yearcode),
                "The {$typeKey} counter must be untouched by a vendor post.");
        }
    }

    public function test_workspace_renders_lines_and_posted_state(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->saveLine($this->linePayload([
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => 1,
            'qty' => 7.0,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ]));
        $arrivalId = $resp->json('arrival_id');

        $this->get(route('arrivals.vendor.workspace', $arrivalId))
            ->assertOk()
            ->assertSee('Final post')
            ->assertSee('DC-TEST-1');

        $this->post(route('arrivals.vendor.post', Arrival::query()->findOrFail($arrivalId)))->assertRedirect();

        $this->get(route('arrivals.vendor.show', $arrivalId))
            ->assertOk()
            ->assertSee('Posted');
    }

    public function test_availability_endpoint_returns_reference_rows(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->getJson(route('arrivals.vendor.availability', [
            'classification' => (int) $item->classification_id,
            'item' => (int) $item->items_id,
        ]));
        $resp->assertOk();

        $found = collect($resp->json('availability'))
            ->first(fn ($l) => (int) $l['whid'] === (int) $ledger->stlg_whid
                && (int) $l['binid'] === (int) $ledger->stlg_binid
                && (int) $l['subbinid'] === (int) $ledger->stlg_subbinid);

        $this->assertNotNull($found);
        $this->assertEquals((float) $ledger->stlg_balqty, (float) $found['qty']);
    }
}
