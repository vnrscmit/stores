<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\FinancialYear;
use App\Models\Item;
use App\Models\ItemTransfer;
use App\Models\ItemTransferItem;
use App\Models\StockLedgerGood;
use App\Models\SubBin;
use App\Models\User;
use App\Support\ArrivalStatus;
use App\Support\DocumentNumber;
use App\Support\FiscalYear;
use App\Support\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 9 slice 5 acceptance: the ITI/ITA inter-item transfer module
 * (legacy add_interitem.php + getuser_iitupdate.php + add_iitr_preview.php).
 *
 * Posting semantics under test (docs/PHASE9.md §2.4):
 *  - one ITI out per source good-ledger row group (tr = Σ ups_to/qty_to,
 *    bal = op − tr, trtype 'IT', subtype 'ITI');
 *  - one ITA in per destination row (subtype 'ITA') with the verbatim
 *    legacy quirk: bal = tr when the destination opening is 0/0 (reset
 *    instead of add);
 *  - conservation Σ ITI = Σ ITA per document; source drained to zero flips
 *    its sub-bin to 'Empty', destinations flip to 'Good';
 *  - iitr_code from the `iitr` counter (TIIT…) + iitrflg/status posted;
 *  - transfer quantity may not exceed the source balance.
 */
class Phase9ItemTransferTest extends TestCase
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

        // Hermeticity: remove artifacts previous runs posted. The ledgers
        // are append-only, so deleting the posted IT rows reverts every
        // item x location balance to its migrated baseline.
        $fy = FiscalYear::yearcode();

        $testTransferIds = ItemTransfer::query()->where('yearcode', $fy)->pluck('iitr_id');

        ItemTransferItem::query()->whereIn('iitr_id', $testTransferIds)->delete();
        StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')
            ->whereIn('stlg_trsubtype', ['ITI', 'ITA'])
            ->whereIn('stlg_trid', $testTransferIds)->delete();
        ItemTransfer::query()->whereIn('iitr_id', $testTransferIds)->delete();
        // Suites seed destination openings with trid=0 vendor rows (real
        // posts always carry a document id) — sweep them too.
        StockLedgerGood::query()
            ->where('stlg_trtype', 'Arrival')->where('stlg_trsubtype', 'Vendor')
            ->where('stlg_trid', 0)->delete();
        DB::table('audit_logs')->where('module', 'itransfer.conversion')->delete();

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
     * transfer from, returning [item, ledger].
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

    /**
     * A different active item of the same classification to convert into.
     */
    private function destinationItem(Item $source): Item
    {
        $dest = Item::query()
            ->where('classification_id', $source->classification_id)
            ->where('items_id', '!=', $source->items_id)
            ->where('actstatus', 'Active')
            ->first();

        if ($dest !== null) {
            return $dest;
        }

        // Legacy data has one-item classifications; a minimal active item
        // keeps the module testable there.
        $item = new Item;
        $item->classification_id = $source->classification_id;
        $item->stores_item = 'TEST-DEST-ITEM-'.uniqid();
        $item->uom = $source->uom;
        $item->actstatus = 'Active';
        $item->save();

        return $item;
    }

    /**
     * A real destination sub-bin (the migrated sub_bins carry placeholder
     * rows with NULL whid/binid — those are never valid targets), distinct
     * from the given locations to avoid. $exclude holds [whid, binid,
     * subbinid] triples — one flat or nested, both are accepted.
     */
    private function destinationLocation(array $exclude = []): array
    {
        // Accept flat triples [whid, binid, subbinid] as well as nested
        // [[whid, binid, subbinid], ...] entries — both shapes were
        // historically passed here. A flat triple is a 3-element list of
        // scalars; wrap it once so it survives as a single location.
        if (count($exclude) === 3 && array_is_list($exclude) && ! is_array($exclude[0])) {
            $exclude = [$exclude];
        }

        $rows = DB::table('sub_bins')
            ->where('sid', '!=', 0)
            ->whereNotNull('whid')
            ->whereNotNull('binid')
            ->orderBy('sid')
            ->get(['whid', 'binid', 'sid']);

        foreach ($rows as $row) {
            foreach ($exclude as [$wh, $bin, $sub]) {
                if ((int) $row->whid === $wh && (int) $row->binid === $bin && (int) $row->sid === $sub) {
                    continue 2;
                }
            }

            return ['whid' => (int) $row->whid, 'binid' => (int) $row->binid, 'subbin' => (int) $row->sid];
        }

        $this->fail('No real sub-bin location available for the destination.');
    }

    /** Source header + one destination line against the given row. */
    private function payload(Item $source, StockLedgerGood $ledger, Item $dest, array $loc, float $qty, int $ups = 1, array $over = []): array
    {
        return array_merge([
            'transfer_id' => null,
            'tdate' => now()->toDateString(),
            'remarks' => 'Phase 9 slice 5 test',
            'classification_id' => (int) $source->classification_id,
            'items_id' => (int) $source->items_id,
            'rowid' => (int) $ledger->stlg_id,
            'ups_from' => (int) $ledger->stlg_balups,
            'qty_from' => (float) $ledger->stlg_balqty,
            'targets' => [[
                'classification_id' => (int) $dest->classification_id,
                'items_id' => (int) $dest->items_id,
                'whid' => $loc['whid'],
                'binid' => $loc['binid'],
                'subbin' => $loc['subbin'],
                'ups_to' => $ups,
                'qty_to' => $qty,
            ]],
        ], $over);
    }

    private function saveLine(array $payload): TestResponse
    {
        return $this->postJson(route('itransfers.lines.store'), $payload);
    }

    public function test_module_requires_authentication(): void
    {
        $this->get(route('itransfers.index'))->assertRedirect(route('login'));
        $this->postJson(route('itransfers.lines.store'), [])->assertStatus(401);
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
            $this->get(route('itransfers.index'))->assertStatus(500);
        } finally {
            $fy->years_status = 'a';
            $fy->save();
            FiscalYear::invalidateForTesting();
        }

        $this->get(route('itransfers.index'))->assertOk();
    }

    public function test_conversion_conserves_stock_and_drains_the_source(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        $opQty = (float) $ledger->stlg_balqty;

        // Full drain: the transfer quantity equals the source balance.
        $resp = $this->saveLine($this->payload($source, $ledger, $dest, $loc, $opQty));
        $resp->assertOk();
        $transferId = (int) $resp->json('transfer_id');

        // Destination opening as of BEFORE the post (the line save never
        // touches the ledger; after the post the latest row IS the ITA).
        $destOpening = StockLedgerService::latestRow(
            (int) $dest->items_id, $loc['whid'], $loc['binid'], $loc['subbin'],
        );
        $destOpQty = (float) ($destOpening->stlg_balqty ?? 0);
        $destOpUps = (int) ($destOpening->stlg_balups ?? 0);

        $transfer = ItemTransfer::query()->findOrFail($transferId);
        $this->assertSame((int) $source->items_id, (int) $transfer->items_id_from);
        $this->assertSame(0, (int) $transfer->iitrflg);
        $this->assertSame(ArrivalStatus::OPEN, $transfer->status);

        $this->post(route('itransfers.post', $transfer))->assertRedirect();

        $iti = StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')->where('stlg_trsubtype', 'ITI')
            ->where('stlg_trid', $transferId)->firstOrFail();
        $this->assertSame((int) $ledger->stlg_whid, (int) $iti->stlg_whid);
        $this->assertSame((int) $ledger->stlg_subbinid, (int) $iti->stlg_subbinid);
        $this->assertSame((int) $source->items_id, (int) $iti->stlg_tritemid);
        $this->assertEquals($opQty, (float) $iti->stlg_opqty);
        $this->assertEquals($opQty, (float) $iti->stlg_trqty);
        $this->assertEquals(0.0, (float) $iti->stlg_balqty);

        $ita = StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')->where('stlg_trsubtype', 'ITA')
            ->where('stlg_trid', $transferId)->firstOrFail();

        // Conservation: what left the source arrived at the destination.
        $this->assertEquals((float) $iti->stlg_trqty, (float) $ita->stlg_trqty);
        $this->assertEquals((float) $iti->stlg_trups, (float) $ita->stlg_trups);

        // The destination balance follows the verbatim legacy math: reset
        // to the transfer when the opening is 0/0, otherwise op + tr.
        $this->assertEquals($destOpQty, (float) $ita->stlg_opqty);
        $this->assertEquals($destOpQty > 0 ? $destOpQty + (float) $iti->stlg_trqty : (float) $iti->stlg_trqty,
            (float) $ita->stlg_balqty);
        $this->assertEquals($destOpUps, (int) $ita->stlg_opups);
        $this->assertEquals($destOpUps > 0 ? $destOpUps + (int) $iti->stlg_trups : (int) $iti->stlg_trups,
            (int) $ita->stlg_balups);

        // Source sub-bin drained to 'Empty', destination flipped to 'Good'.
        $this->assertSame('Empty', SubBin::query()->where('sid', $iti->stlg_subbinid)->value('status'));
        $this->assertSame('Good', SubBin::query()->where('sid', $ita->stlg_subbinid)->value('status'));
    }

    public function test_destination_reset_quirk_when_opening_is_zero(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        // The destination location has no ledger rows for the item.
        $this->assertSame(0, StockLedgerGood::query()
            ->where('stlg_tritemid', (int) $dest->items_id)
            ->where('stlg_whid', $loc['whid'])
            ->where('stlg_binid', $loc['binid'])
            ->where('stlg_subbinid', $loc['subbin'])
            ->count());

        $resp = $this->saveLine($this->payload($source, $ledger, $dest, $loc, 2.0, 1));
        $resp->assertOk();
        $this->post(route('itransfers.post', ItemTransfer::query()->findOrFail((int) $resp->json('transfer_id'))))->assertRedirect();

        // Verbatim add_iitr_preview.php: opups==0/opqty==0 -> bal = tr
        // (reset instead of add).
        $ita = StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')->where('stlg_trsubtype', 'ITA')
            ->where('stlg_trid', (int) $resp->json('transfer_id'))->firstOrFail();
        $this->assertEquals(0, (int) $ita->stlg_opups);
        $this->assertEquals(0.0, (float) $ita->stlg_opqty);
        $this->assertEquals(1, (int) $ita->stlg_balups);
        $this->assertEquals(2.0, (float) $ita->stlg_balqty);
    }

    public function test_destination_adds_when_opening_is_positive(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        // Seed the destination location with a positive opening balance.
        StockLedgerService::post([
            'direction' => 'in',
            'yearcode' => FiscalYear::yearcode(),
            'trtype' => 'Arrival',
            'trsubtype' => 'Vendor',
            'trid' => 0,
            'trdate' => now()->toDateString(),
            'classid' => (int) $dest->classification_id,
            'item_id' => (int) $dest->items_id,
            'whid' => $loc['whid'],
            'binid' => $loc['binid'],
            'subbinid' => $loc['subbin'],
            'ups' => 1,
            'qty' => 4.0,
        ]);

        $resp = $this->saveLine($this->payload($source, $ledger, $dest, $loc, 2.0, 1));
        $resp->assertOk();
        $this->post(route('itransfers.post', ItemTransfer::query()->findOrFail((int) $resp->json('transfer_id'))))->assertRedirect();

        $ita = StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')->where('stlg_trsubtype', 'ITA')
            ->where('stlg_trid', (int) $resp->json('transfer_id'))->firstOrFail();
        $this->assertEquals(4.0, (float) $ita->stlg_opqty);
        $this->assertEquals(2.0, (float) $ita->stlg_trqty);
        $this->assertEquals(6.0, (float) $ita->stlg_balqty);
        $this->assertEquals(2, (int) $ita->stlg_balups);
    }

    public function test_multi_target_group_conserves_and_numbers_once(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $locA = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);
        $locB = $this->destinationLocation([
            [(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid],
            [$locA['whid'], $locA['binid'], $locA['subbin']],
        ]);

        $opQty = (float) $ledger->stlg_balqty;
        $half = round($opQty / 2, 3);

        DocumentNumber::prime('iitr', FiscalYear::yearcode(), 0);

        $payload = $this->payload($source, $ledger, $dest, $locA, $half, 1);
        $payload['targets'][] = [
            'classification_id' => (int) $dest->classification_id,
            'items_id' => (int) $dest->items_id,
            'whid' => $locB['whid'],
            'binid' => $locB['binid'],
            'subbin' => $locB['subbin'],
            'ups_to' => 1,
            'qty_to' => $opQty - $half,
        ];

        $resp = $this->saveLine($payload);
        $resp->assertOk();
        $transferId = (int) $resp->json('transfer_id');

        $this->assertSame(2, ItemTransferItem::query()->where('iitr_id', $transferId)->count());

        $this->post(route('itransfers.post', ItemTransfer::query()->findOrFail($transferId)))->assertRedirect();

        $transfer = ItemTransfer::query()->findOrFail($transferId);

        // Committed serial from the iitr counter (TIIT…) + posted markers.
        $this->assertSame(1, (int) $transfer->iitr_code);
        $this->assertSame(1, (int) $transfer->iitrflg);
        $this->assertSame(ArrivalStatus::POSTED, $transfer->status);
        $this->assertSame('TIIT1/'.FiscalYear::yearcode(),
            DocumentNumber::pretty('iitr', (int) $transfer->iitr_code, (string) $transfer->yearcode));
        $this->assertSame(1, DocumentNumber::current('iitr', FiscalYear::yearcode()));

        $iti = StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')->where('stlg_trsubtype', 'ITI')
            ->where('stlg_trid', $transferId)->firstOrFail();
        $itas = StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')->where('stlg_trsubtype', 'ITA')
            ->where('stlg_trid', $transferId)->get();
        $this->assertSame(2, $itas->count());

        // Conservation across the group: Σ ITA = the single ITI row.
        $this->assertEquals((float) $iti->stlg_trqty, round((float) $itas->sum('stlg_trqty'), 3));
        $this->assertEquals($opQty, (float) $iti->stlg_trqty);
        $this->assertEquals(0.0, (float) $iti->stlg_balqty);
    }

    public function test_transfer_beyond_source_balance_is_rejected(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        $this->saveLine($this->payload($source, $ledger, $dest, $loc, (float) $ledger->stlg_balqty + 5.0))
            ->assertStatus(422);

        $this->assertSame(0, ItemTransfer::query()
            ->where('yearcode', FiscalYear::yearcode())->count());
        $this->assertSame(0, StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')->count());
    }

    public function test_line_save_validates_source_and_destination(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        // The destination item must differ from the source.
        $bad = $this->payload($source, $ledger, $dest, $loc, 1.0);
        $bad['targets'][0]['items_id'] = (int) $source->items_id;
        $this->postJson(route('itransfers.lines.store'), $bad)->assertStatus(422);

        // The source row must belong to the source item.
        $bad = $this->payload($source, $ledger, $dest, $loc, 1.0);
        $bad['rowid'] = (int) $ledger->stlg_id + 1000000;
        $this->postJson(route('itransfers.lines.store'), $bad)->assertStatus(422);

        // Destination locations must be a real wh -> bin -> sub-bin chain.
        $bad = $this->payload($source, $ledger, $dest, $loc, 1.0);
        $bad['targets'][0]['binid'] = 99999999;
        $this->postJson(route('itransfers.lines.store'), $bad)->assertStatus(422);

        $this->assertSame(0, ItemTransfer::query()
            ->where('yearcode', FiscalYear::yearcode())->count());
    }

    public function test_line_edit_replaces_the_source_group(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $locA = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);
        $locB = $this->destinationLocation([
            [(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid],
            [$locA['whid'], $locA['binid'], $locA['subbin']],
        ]);

        // Two destination rows against one source row (legacy group).
        $payload = $this->payload($source, $ledger, $dest, $locA, 1.0);
        $payload['targets'][] = [
            'classification_id' => (int) $dest->classification_id,
            'items_id' => (int) $dest->items_id,
            'whid' => $locB['whid'],
            'binid' => $locB['binid'],
            'subbin' => $locB['subbin'],
            'ups_to' => 1,
            'qty_to' => 1.0,
        ];
        $transferId = (int) $this->saveLine($payload)->json('transfer_id');
        $this->assertSame(2, ItemTransferItem::query()->where('iitr_id', $transferId)->count());

        // Editing the group replaces all its rows (legacy delete-and-reinsert).
        $replace = $this->payload($source, $ledger, $dest, $locB, 2.0, 1, ['transfer_id' => $transferId]);
        $this->putJson(route('itransfers.lines.update', (int) $ledger->stlg_id), $replace)->assertOk();
        $this->assertSame(1, ItemTransferItem::query()->where('iitr_id', $transferId)->count());
        $this->assertSame($locB['subbin'],
            (int) ItemTransferItem::query()->where('iitr_id', $transferId)->value('subbinid'));

        // Removing the group clears it.
        $this->deleteJson(route('itransfers.lines.delete', (int) $ledger->stlg_id),
            ['transfer_id' => $transferId])->assertOk();
        $this->assertSame(0, ItemTransferItem::query()->where('iitr_id', $transferId)->count());
    }

    public function test_posting_is_idempotent(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        $transferId = (int) $this->saveLine($this->payload($source, $ledger, $dest, $loc, 1.0))->json('transfer_id');
        $transfer = ItemTransfer::query()->findOrFail($transferId);

        // The counter persists across tests in the shared database; the
        // idempotency contract is exactly one consumed serial per post.
        $before = DocumentNumber::current('iitr', FiscalYear::yearcode());

        $this->post(route('itransfers.post', $transfer))->assertRedirect();
        $code = (int) $transfer->refresh()->iitr_code;
        $this->assertSame($before + 1, DocumentNumber::current('iitr', FiscalYear::yearcode()));

        $this->post(route('itransfers.post', $transfer))->assertRedirect();

        $this->assertSame($code, (int) $transfer->refresh()->iitr_code);
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')->where('stlg_trsubtype', 'ITI')
            ->where('stlg_trid', $transferId)->count());
        $this->assertSame(1, StockLedgerGood::query()
            ->where('stlg_trtype', 'IT')->where('stlg_trsubtype', 'ITA')
            ->where('stlg_trid', $transferId)->count());
        $this->assertSame($before + 1, DocumentNumber::current('iitr', FiscalYear::yearcode()));
    }

    public function test_posted_transfer_is_immutable(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        $transferId = (int) $this->saveLine($this->payload($source, $ledger, $dest, $loc, 1.0))->json('transfer_id');
        $transfer = ItemTransfer::query()->findOrFail($transferId);
        $this->post(route('itransfers.post', $transfer))->assertRedirect();

        // Line edits/deletes are rejected after posting.
        $this->putJson(route('itransfers.lines.update', (int) $ledger->stlg_id),
            $this->payload($source, $ledger, $dest, $loc, 2.0, 1, ['transfer_id' => $transferId]))->assertStatus(422);
        $this->deleteJson(route('itransfers.lines.delete', (int) $ledger->stlg_id),
            ['transfer_id' => $transferId])->assertStatus(422);

        // The header cannot be re-opened either.
        $this->putJson(route('itransfers.header.update', $transferId),
            ['tdate' => now()->toDateString(), 'remarks' => 'nope'])->assertStatus(422);
    }

    public function test_workspace_and_show_render(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        $transferId = (int) $this->saveLine($this->payload($source, $ledger, $dest, $loc, 1.5))->json('transfer_id');

        $this->get(route('itransfers.workspace', $transferId))
            ->assertOk()
            ->assertSee('Final post')
            ->assertSee('Inter Item Transfer');

        $this->post(route('itransfers.post', ItemTransfer::query()->findOrFail($transferId)))->assertRedirect();

        $this->get(route('itransfers.show', $transferId))
            ->assertOk()
            ->assertSee('TIIT');
    }

    public function test_queue_lists_open_and_posted_documents(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        $transferId = (int) $this->saveLine($this->payload($source, $ledger, $dest, $loc, 1.0))->json('transfer_id');

        $this->get(route('itransfers.index', ['stage' => 'open']))
            ->assertOk()
            ->assertSee('Open workspace');

        $this->post(route('itransfers.post', ItemTransfer::query()->findOrFail($transferId)))->assertRedirect();

        $this->get(route('itransfers.index', ['stage' => 'posted']))
            ->assertOk()
            ->assertSee('View');
    }

    public function test_numbering_is_isolated_from_other_counters(): void
    {
        $this->operator();
        [$source, $ledger] = $this->stockedItem();
        $dest = $this->destinationItem($source);
        $loc = $this->destinationLocation([(int) $ledger->stlg_whid, (int) $ledger->stlg_binid, (int) $ledger->stlg_subbinid]);

        $yearcode = FiscalYear::yearcode();
        DocumentNumber::prime('iitr', $yearcode, 0);

        // The other counters carry values from earlier runs in the shared
        // database; capture and assert they do not move (differential).
        $before = collect(['eindent', 'issue.eindent', 'arrival.vendor', 'arrival.stocktr', 'arrival.internal', 'discard'])
            ->mapWithKeys(fn ($c) => [$c => DocumentNumber::current($c, $yearcode)]);

        $transferId = (int) $this->saveLine($this->payload($source, $ledger, $dest, $loc, 1.0))->json('transfer_id');
        $this->post(route('itransfers.post', ItemTransfer::query()->findOrFail($transferId)))->assertRedirect();

        $this->assertSame(1, (int) ItemTransfer::query()->findOrFail($transferId)->iitr_code);

        // No other document counter may move for an ITI/ITA post.
        foreach ($before as $counter => $value) {
            $this->assertSame($value, DocumentNumber::current($counter, $yearcode),
                "The {$counter} counter must be untouched by inter-item transfer posts.");
        }
    }
}
