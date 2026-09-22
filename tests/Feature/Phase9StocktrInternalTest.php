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
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 9 slices 3-4 acceptance: stock transfer in (STN) and internal return
 * to stores, sharing ArrivalController through typed routes. Posting is the
 * vendor skeleton minus the party ledger and DC block; internal return
 * stores exsh = 0/0 verbatim (getuser_imroupdateform.php hardcodes it).
 */
class Phase9StocktrInternalTest extends TestCase
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

        // Hermeticity: remove artifacts previous runs posted for BOTH types.
        // The ledgers are append-only, so deleting the posted rows reverts
        // every item x location balance to its migrated baseline.
        $fy = FiscalYear::yearcode();

        $testArrivalIds = Arrival::query()
            ->whereIn('arrival_type', ['Stocktransfer', 'Internalreturn'])
            ->where('yearcode', $fy)
            ->pluck('arrival_id');

        ArrivalSloc::query()->whereIn('arr_tr_id', $testArrivalIds)->delete();
        ArrivalItem::query()->whereIn('arrival_id', $testArrivalIds)->delete();
        StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')
            ->whereIn('stlg_trsubtype', ['Stocktransfer', 'Internalreturn'])
            ->whereIn('stlg_trid', $testArrivalIds)->delete();
        StockLedgerDamage::query()
            ->where('stld_trtype', 'Arrival')
            ->whereIn('stld_trsubtype', ['Stocktransfer', 'Internalreturn'])
            ->whereIn('stld_trid', $testArrivalIds)->delete();
        // Neither type writes a party-ledger row in legacy or in the port;
        // the sweep only guards against artifacts of a defective run.
        PartyLedger::query()
            ->where('pldg_trtype', 'Arrival')
            ->whereIn('pldg_trsubtype', ['Stocktransfer', 'Internalreturn'])
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

    /** Save one line for a given arrival type (routes are typed). */
    private function saveLine(string $type, array $payload): TestResponse
    {
        return $this->postJson(route("arrivals.{$type}.lines.store"), $payload);
    }

    /** stocktr header + line: STN no, no DC block, no party ledger. */
    private function stocktrPayload(array $item, array $over = []): array
    {
        return array_merge([
            'stnno' => 'STN-TEST-1',
            'arrival_date' => now()->toDateString(),
            'tmode' => 'By Hand',
            'pname_byhand' => 'Test Bearer',
            'classification_id' => $item['classification_id'],
            'items_id' => $item['items_id'],
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

    /** internal-return header + line: party + stageret/retid, type Good. */
    private function internalPayload(array $item, array $over = []): array
    {
        return array_merge([
            'party_id' => $this->partyId(),
            'stageret' => 'Test Stage',
            'retid' => 'Test Receiver',
            'type' => 'Good',
            'arrival_date' => now()->toDateString(),
            'tmode' => 'By Hand',
            'pname_byhand' => 'Test Bearer',
            'classification_id' => $item['classification_id'],
            'items_id' => $item['items_id'],
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

    private function itemBase(Item $item, StockLedgerGood $ledger, float $qty, int $ups = 1): array
    {
        return [
            'items_id' => (int) $item->items_id,
            'classification_id' => (int) $item->classification_id,
            'ups' => $ups,
            'qty' => $qty,
            'stlg_id' => (int) $ledger->stlg_id,
            'whid' => (int) $ledger->stlg_whid,
            'binid' => (int) $ledger->stlg_binid,
            'subbin' => (int) $ledger->stlg_subbinid,
        ];
    }

    public function test_arrival_types_require_authentication(): void
    {
        $this->get(route('arrivals.stocktr.index'))->assertRedirect(route('login'));
        $this->get(route('arrivals.internal.index'))->assertRedirect(route('login'));
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
            $this->get(route('arrivals.stocktr.index'))->assertStatus(500);
            $this->get(route('arrivals.internal.index'))->assertStatus(500);
        } finally {
            $fy->years_status = 'a';
            $fy->save();
            FiscalYear::invalidateForTesting();
        }

        $this->get(route('arrivals.stocktr.index'))->assertOk();
        $this->get(route('arrivals.internal.index'))->assertOk();
    }

    public function test_create_screens_render_their_type_headers(): void
    {
        $this->operator();

        $this->get(route('arrivals.stocktr.create'))
            ->assertOk()
            ->assertSee('STN No');

        $this->get(route('arrivals.internal.create'))
            ->assertOk()
            ->assertSee('Returned From Stage');
    }

    public function test_stocktr_good_receipt_posts_stocktransfer_ledger_without_party_ledger(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $opBalance = (float) $ledger->stlg_balqty;

        $resp = $this->saveLine('stocktr', $this->stocktrPayload($this->itemBase($item, $ledger, 6.0)));
        $resp->assertOk();
        $arrivalId = $resp->json('arrival_id');

        $arrival = Arrival::query()->findOrFail($arrivalId);
        $this->assertSame('Stocktransfer', $arrival->arrival_type);
        $this->assertSame('STN-TEST-1', $arrival->stnno);
        $this->assertSame(0, (int) $arrival->arrtrflag);
        $this->assertSame(ArrivalStatus::OPEN, $arrival->status);
        $this->assertNotNull($arrival->arrival_code);

        $this->post(route('arrivals.stocktr.post', $arrival))->assertRedirect();

        $arrival->refresh();
        $this->assertSame(1, (int) $arrival->arrtrflag);
        $this->assertSame(ArrivalStatus::POSTED, $arrival->status);

        $row = StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Stocktransfer')
            ->where('stlg_trid', $arrivalId)
            ->where('stlg_tritemid', (int) $item->items_id)
            ->firstOrFail();
        $this->assertEquals($opBalance, (float) $row->stlg_opqty);
        $this->assertEquals(6.0, (float) $row->stlg_trqty);
        $this->assertEquals($opBalance + 6.0, (float) $row->stlg_balqty);

        // Sub-bin flipped to Good by the 'in' direction.
        $this->assertSame('Good', SubBin::query()->where('sid', $row->stlg_subbinid)->value('status'));

        // No party ledger is written for a stock transfer in (legacy: the
        // preview has no tbl_party_ldg insert).
        $this->assertSame(0, PartyLedger::query()
            ->where('pldg_trtype', 'Arrival')->where('pldg_trsubtype', 'Stocktransfer')
            ->where('pldg_trid', $arrivalId)->count());
    }

    public function test_stocktr_line_stores_zero_exsh_and_zero_dc(): void
    {
        // Legacy stupdateform inserts exsh from the (empty) DC block -> 0/0,
        // and dc stays 0/0 for stock transfers.
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->saveLine('stocktr', $this->stocktrPayload($this->itemBase($item, $ledger, 4.0)));
        $resp->assertOk();
        $arrivalId = $resp->json('arrival_id');

        $line = ArrivalItem::query()->where('arrival_id', $arrivalId)->firstOrFail();
        $this->assertEquals(0.0, (float) $line->qty_per_dc);
        $this->assertSame(0, (int) $line->ups_per_dc);
        $this->assertEquals(0.0, (float) $line->exsh_qty);
        $this->assertSame(0, (int) $line->exsh_ups);
    }

    public function test_internal_return_posts_internalreturn_ledger(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();
        $partyId = $this->partyId();

        $opBalance = (float) $ledger->stlg_balqty;

        $resp = $this->saveLine('internal', $this->internalPayload($this->itemBase($item, $ledger, 8.0)));
        $resp->assertOk();
        $arrivalId = $resp->json('arrival_id');

        $arrival = Arrival::query()->findOrFail($arrivalId);
        $this->assertSame('Internalreturn', $arrival->arrival_type);
        $this->assertSame('Test Stage', $arrival->stageret);
        $this->assertSame('Test Receiver', $arrival->retid);
        $this->assertSame('Good', $arrival->type);
        $this->assertSame($partyId, (int) $arrival->party_id);

        $this->post(route('arrivals.internal.post', $arrival))->assertRedirect();

        $row = StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Internalreturn')
            ->where('stlg_trid', $arrivalId)
            ->where('stlg_tritemid', (int) $item->items_id)
            ->firstOrFail();
        $this->assertEquals($opBalance, (float) $row->stlg_opqty);
        $this->assertEquals(8.0, (float) $row->stlg_trqty);
        $this->assertEquals($opBalance + 8.0, (float) $row->stlg_balqty);
        $this->assertSame((string) $partyId, (string) $row->stlg_trpartyid);

        // Party ledger untouched (the internal-return preview writes none).
        $this->assertSame(0, PartyLedger::query()
            ->where('pldg_trtype', 'Arrival')->where('pldg_trsubtype', 'Internalreturn')
            ->where('pldg_trid', $arrivalId)->count());

        // Sub-bin flipped to Good.
        $this->assertSame('Good', SubBin::query()->where('sid', $row->stlg_subbinid)->value('status'));
    }

    public function test_internal_return_line_stores_zero_exsh_verbatim(): void
    {
        // getuser_imroupdateform.php hardcodes exsh = 0/0 (and dc = 0/0) in
        // the internal-return line insert.
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $resp = $this->saveLine('internal', $this->internalPayload($this->itemBase($item, $ledger, 5.0)));
        $resp->assertOk();
        $arrivalId = $resp->json('arrival_id');

        $line = ArrivalItem::query()->where('arrival_id', $arrivalId)->firstOrFail();
        $this->assertEquals(0.0, (float) $line->qty_per_dc);
        $this->assertSame(0, (int) $line->ups_per_dc);
        $this->assertEquals(0.0, (float) $line->exsh_qty);
        $this->assertSame(0, (int) $line->exsh_ups);
    }

    public function test_internal_return_damage_receipt_posts_damage_ledger(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $opDamage = (float) (StockLedgerDamage::query()
            ->where('stld_tritemid', (int) $item->items_id)
            ->where('stld_whid', (int) $ledger->stlg_whid)
            ->where('stld_binid', (int) $ledger->stlg_binid)
            ->where('stld_subbinid', (int) $ledger->stlg_subbinid)
            ->orderByDesc('stld_id')->value('stld_balqty') ?? 0);

        $resp = $this->saveLine('internal', $this->internalPayload(
            $this->itemBase($item, $ledger, 0.0, 0),
            [
                'ups_good' => 0,
                'qty_good' => 0,
                'ups_damage' => 0,
                'qty_damage' => 3.0,
                'slocs' => [[
                    'stlg_id' => (int) $ledger->stlg_id,
                    'whid' => (int) $ledger->stlg_whid,
                    'binid' => (int) $ledger->stlg_binid,
                    'subbin' => (int) $ledger->stlg_subbinid,
                    'ups_good' => 0,
                    'qty_good' => 0,
                    'ups_damage' => 0,
                    'qty_damage' => 3.0,
                ]],
            ]
        ));
        $resp->assertOk();
        $arrivalId = $resp->json('arrival_id');

        $this->post(route('arrivals.internal.post', Arrival::query()->findOrFail($arrivalId)))->assertRedirect();

        $this->assertSame(0, StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Internalreturn')
            ->where('stlg_trid', $arrivalId)->count());

        $dmg = StockLedgerDamage::query()
            ->where('stld_trtype', 'Arrival')->where('stld_trsubtype', 'Internalreturn')
            ->where('stld_trid', $arrivalId)->firstOrFail();
        $this->assertEquals($opDamage, (float) $dmg->stld_opqty);
        $this->assertEquals($opDamage + 3.0, (float) $dmg->stld_balqty);
    }

    public function test_posting_is_idempotent_for_both_types(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();
        $base = $this->itemBase($item, $ledger, 2.0);

        $stId = $this->saveLine('stocktr', $this->stocktrPayload($base))->json('arrival_id');
        $inId = $this->saveLine('internal', $this->internalPayload($base))->json('arrival_id');

        $st = Arrival::query()->findOrFail($stId);
        $in = Arrival::query()->findOrFail($inId);

        $this->post(route('arrivals.stocktr.post', $st))->assertRedirect();
        $this->post(route('arrivals.internal.post', $in))->assertRedirect();

        $stCode = (int) $st->refresh()->arr_code;
        $inCode = (int) $in->refresh()->arr_code;

        $this->post(route('arrivals.stocktr.post', $st))->assertRedirect();
        $this->post(route('arrivals.internal.post', $in))->assertRedirect();

        $this->assertSame($stCode, (int) $st->refresh()->arr_code);
        $this->assertSame($inCode, (int) $in->refresh()->arr_code);
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Stocktransfer')
            ->where('stlg_trid', $stId)->count());
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Internalreturn')
            ->where('stlg_trid', $inId)->count());
    }

    public function test_posted_arrivals_are_immutable_for_both_types(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();
        $base = $this->itemBase($item, $ledger, 2.0);

        $stId = $this->saveLine('stocktr', $this->stocktrPayload($base))->json('arrival_id');
        $inId = $this->saveLine('internal', $this->internalPayload($base))->json('arrival_id');
        $this->post(route('arrivals.stocktr.post', Arrival::query()->findOrFail($stId)))->assertRedirect();
        $this->post(route('arrivals.internal.post', Arrival::query()->findOrFail($inId)))->assertRedirect();

        $stLine = ArrivalItem::query()->where('arrival_id', $stId)->firstOrFail();
        $inLine = ArrivalItem::query()->where('arrival_id', $inId)->firstOrFail();

        // Line edits and deletes are rejected after posting.
        $this->putJson(route('arrivals.stocktr.lines.update', $stLine->arrsub_id),
            $this->stocktrPayload($this->itemBase($item, $ledger, 5.0)))->assertStatus(422);
        $this->deleteJson(route('arrivals.internal.lines.delete', $inLine->arrsub_id))->assertStatus(422);
    }

    public function test_per_type_numbering_is_isolated_across_all_three_types(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $yearcode = FiscalYear::yearcode();

        // Reset all three arrival counters so the assertion observes exactly
        // one consumed serial per type (the active FY has no legacy arrivals
        // in the test template — data stops at 14-15).
        foreach (ArrivalNumbering::TYPES as $typeKey => $spec) {
            DocumentNumber::prime($spec['counter'], $yearcode, 0);
            DocumentNumber::prime($spec['counter'].'.n', $yearcode, 0);
        }

        $base = $this->itemBase($item, $ledger, 1.0);

        $stId = $this->saveLine('stocktr', $this->stocktrPayload($base))->json('arrival_id');
        $inId = $this->saveLine('internal', $this->internalPayload($base))->json('arrival_id');
        $this->post(route('arrivals.stocktr.post', Arrival::query()->findOrFail($stId)))->assertRedirect();
        $this->post(route('arrivals.internal.post', Arrival::query()->findOrFail($inId)))->assertRedirect();

        $this->assertSame(1, (int) Arrival::query()->findOrFail($stId)->arr_code);
        $this->assertSame(1, (int) Arrival::query()->findOrFail($inId)->arr_code);

        // The vendor counter must be untouched by stocktr/internal posts.
        $this->assertSame(0,
            DocumentNumber::current(ArrivalNumbering::TYPES['vendor']['counter'], $yearcode),
            'The vendor counter must be untouched by stocktr/internal posts.');
    }

    public function test_workspace_and_show_render_for_both_types(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();
        $base = $this->itemBase($item, $ledger, 7.0);

        $stId = $this->saveLine('stocktr', $this->stocktrPayload($base))->json('arrival_id');
        $inId = $this->saveLine('internal', $this->internalPayload($base))->json('arrival_id');

        $this->get(route('arrivals.stocktr.workspace', $stId))
            ->assertOk()
            ->assertSee('Final post')
            ->assertSee('STN-TEST-1');

        $this->get(route('arrivals.internal.workspace', $inId))
            ->assertOk()
            ->assertSee('Final post');

        $this->post(route('arrivals.stocktr.post', Arrival::query()->findOrFail($stId)))->assertRedirect();
        $this->post(route('arrivals.internal.post', Arrival::query()->findOrFail($inId)))->assertRedirect();

        $this->get(route('arrivals.stocktr.show', $stId))
            ->assertOk()
            ->assertSee('Posted');
        $this->get(route('arrivals.internal.show', $inId))
            ->assertOk()
            ->assertSee('Posted');
    }

    public function test_queue_lists_posted_documents_per_type(): void
    {
        $this->operator();
        [$item, $ledger] = $this->stockedItem();

        $stId = $this->saveLine('stocktr',
            $this->stocktrPayload($this->itemBase($item, $ledger, 3.0)))->json('arrival_id');
        $this->post(route('arrivals.stocktr.post', Arrival::query()->findOrFail($stId)))->assertRedirect();

        $this->get(route('arrivals.stocktr.index', ['stage' => 'posted']))
            ->assertOk()
            ->assertSee('STN-TEST-1');
    }
}
