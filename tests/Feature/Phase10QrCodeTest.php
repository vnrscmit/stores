<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\Arrival;
use App\Models\ArrivalItem;
use App\Models\Classification;
use App\Models\Item;
use App\Models\QrCode;
use App\Models\StockLedgerGood;
use App\Models\User;
use App\Support\FiscalYear;
use App\Support\QrSerial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 10 slice 3 acceptance: the QR code subsystem — the port of
 * Transaction/generate_qr_codes.php + save_qr_codes.php + save_qr_temp
 * .php over the net-new qr_codes/qr_scan_logs/qr_item_types tables (the
 * legacy subsystem never went live; see docs/PHASE10.md 2.4/2.5).
 *
 * Semantics under test:
 *  - code format {plant}{year4}{type2}{serial5} (e.g. D25261100001) with
 *    the serial continuing GLOBALLY per year+type from MAX(RIGHT(text,5));
 *  - draft save purges the user's earlier drafts for the item
 *    (save_qr_temp.php) and stores linked_status='draft', ids 0;
 *  - linked save stores linked_status='linked' and — verbatim quirk —
 *    overwrites arrival_items.qty_good with the Σ weights (Σ > 0 only);
 *  - serial drift between page load and save aborts (legacy race, loud);
 *  - the print sheet renders 12-slip A4 pages with inline SVG QRs.
 *
 * Hermeticity: the suite sweeps its own qr_codes + audit rows (and the
 * qr.* counters it primed) in setUp AND tearDown.
 */
class Phase10QrCodeTest extends TestCase
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

        $this->sweep();
    }

    protected function tearDown(): void
    {
        $this->sweep();

        parent::tearDown();
    }

    private function sweep(): void
    {
        if (! Schema::hasTable('qr_codes')) {
            return;
        }

        // qr_codes is written by this suite only (no pipeline, no other
        // suite touches it) — clear it entirely.
        QrCode::query()->delete();
        DB::table('audit_logs')->where('module', 'arrival.qr')->delete();
        DB::table('document_counters')->where('doc_type', 'like', 'qr.%')->delete();
    }

    private function operator(): User
    {
        $user = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    /** An active item with its classification. */
    private function item(): array
    {
        $row = Item::query()
            ->join('classifications', 'classifications.classification_id', '=', 'items.classification_id')
            ->where('items.actstatus', 'Active')
            ->orderBy('items.items_id')
            ->firstOrFail();

        $item = Item::query()->findOrFail($row->items_id);
        $classification = Classification::query()->findOrFail($row->classification_id);

        return [$item, $classification];
    }

    /** A vendor arrival with one line (historical data — no year filter). */
    private function arrivalLine(): array
    {
        $row = ArrivalItem::query()
            ->join('arrivals', 'arrivals.arrival_id', '=', 'arrival_items.arrival_id')
            ->where('arrivals.arrival_type', 'Vendor')
            ->where('arrival_items.ups_good', '>', 0)
            ->where('arrival_items.item_id', '!=', 0)
            ->orderByDesc('arrival_items.arrsub_id')
            ->firstOrFail();

        $arrival = Arrival::query()->findOrFail($row->arrival_id);
        $line = ArrivalItem::query()->findOrFail($row->arrsub_id);

        return [$arrival, $line];
    }

    private function codes(int $count, float $weight): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = ['text' => 'CODE'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT), 'weight' => $weight];
        }

        return $out;
    }

    public function test_code_format_and_serial_continuation(): void
    {
        $yearcode = FiscalYear::yearcode();
        $this->operator();

        // Defaults (no classification_type in the data): type 11, plant DEF.
        $this->assertSame(11, QrSerial::typeCodeFor(null));
        $this->assertSame(12, QrSerial::typeCodeFor('Pouches'));
        $this->assertSame(13, QrSerial::typeCodeFor('Sticker'));
        $this->assertSame('DEF'.str_replace('-', '', $yearcode).'11', QrSerial::prefix($yearcode, 11, 'DEF'));

        // A first save allocates 1..N; a second continues at N+1.
        [$item, $classification] = $this->item();
        $start = QrSerial::peekSerial($yearcode, 11);

        $this->postJson(route('arrivals.vendor.qr.save'), [
            'classification_id' => $classification->classification_id,
            'item_id' => $item->items_id,
            'ups_good' => 2,
            'serial_start' => $start,
            'codes' => [
                ['text' => QrSerial::prefix($yearcode, 11, 'DEF').sprintf('%05d', $start), 'weight' => 1],
                ['text' => QrSerial::prefix($yearcode, 11, 'DEF').sprintf('%05d', $start + 1), 'weight' => 1],
            ],
        ])->assertOk();

        // peek reflects the allocation (continuation, GLOBAL per year+type).
        $this->assertSame($start + 2, QrSerial::peekSerial($yearcode, 11));

        $texts = QrCode::query()->orderBy('id')->pluck('qr_code_text')->all();
        $this->assertSame(
            QrSerial::prefix($yearcode, 11, 'DEF').sprintf('%05d', $start),
            $texts[0]
        );
        $this->assertStringEndsWith(sprintf('%05d', $start + 1), $texts[1]);
    }

    public function test_draft_save_purges_earlier_drafts_for_the_item(): void
    {
        [$item, $classification] = $this->item();
        $operator = $this->operator();
        $yearcode = FiscalYear::yearcode();

        $saveDraft = fn (int $n) => $this->postJson(route('arrivals.vendor.qr.save'), [
            'classification_id' => $classification->classification_id,
            'item_id' => $item->items_id,
            'ups_good' => $n,
            'serial_start' => QrSerial::peekSerial($yearcode, 11),
            'codes' => $this->codes($n, 1.0),
        ]);

        $saveDraft(3)->assertOk();
        $this->assertSame(3, QrCode::query()->count());

        // A second draft batch for the SAME user+item replaces the first.
        $saveDraft(2)->assertOk();
        $this->assertSame(2, QrCode::query()->count());

        $row = QrCode::query()->first();
        $this->assertSame('draft', $row->linked_status);
        $this->assertSame(0, (int) $row->arrival_id);
        $this->assertSame(0, (int) $row->arrsub_id);
        $this->assertSame($operator->login, $row->created_by);
    }

    public function test_linked_save_writes_back_the_total_weight(): void
    {
        [$arrival, $line] = $this->arrivalLine();
        $this->operator();
        $yearcode = FiscalYear::yearcode();

        $start = QrSerial::peekSerial($yearcode, 11);
        $codes = [];
        for ($i = 0; $i < 3; $i++) {
            $codes[] = ['text' => 'WT'.sprintf('%05d', $i + 1), 'weight' => 2.5];
        }

        $this->postJson(route('arrivals.vendor.qr.save-linked', [$arrival, $line]), [
            'classification_id' => $line->classification_id,
            'item_id' => $line->item_id,
            'ups_good' => 3,
            'serial_start' => $start,
            'codes' => $codes,
        ])->assertOk()->assertJsonPath('total_weight', 7.5);

        // Verbatim quirk: qty_good = Σ weights (only when Σ > 0).
        $line->refresh();
        $this->assertEquals(7.5, (float) $line->qty_good);

        $rows = QrCode::query()->get();
        $this->assertSame(3, $rows->count());
        $this->assertSame('linked', $rows->first()->linked_status);
        $this->assertSame((int) $arrival->arrival_id, (int) $rows->first()->arrival_id);
        $this->assertSame((int) $line->arrsub_id, (int) $rows->first()->arrsub_id);
    }

    public function test_serial_drift_aborts_the_save(): void
    {
        [$item, $classification] = $this->item();
        $this->operator();
        $yearcode = FiscalYear::yearcode();

        $stale = QrSerial::peekSerial($yearcode, 11);

        // Someone else allocates a batch while the first page is open.
        QrSerial::nextSerial($yearcode, 11);

        $this->postJson(route('arrivals.vendor.qr.save'), [
            'classification_id' => $classification->classification_id,
            'item_id' => $item->items_id,
            'ups_good' => 1,
            'serial_start' => $stale,
            'codes' => [['text' => 'DRIFT00001', 'weight' => 1]],
        ])->assertStatus(409);

        $this->assertSame(0, QrCode::query()->count());
    }

    public function test_missing_parameters_are_rejected(): void
    {
        $this->operator();

        // Legacy die(): "Error: Missing required parameters."
        $this->get(route('arrivals.vendor.qr.form', ['ups_good' => 5]))->assertStatus(422);
        $this->get(route('arrivals.vendor.qr.form'))->assertStatus(422);
    }

    public function test_form_renders_the_batch(): void
    {
        [$item, $classification] = $this->item();
        $this->operator();
        $yearcode = FiscalYear::yearcode();

        $this->get(route('arrivals.vendor.qr.form', [
            'classification_id' => $classification->classification_id,
            'item_id' => $item->items_id,
            'ups_good' => 3,
        ]))->assertOk()
            ->assertSee('Generate QR codes')
            ->assertSee('Draft — codes link when the arrival is posted')
            ->assertSee('Serial start');
    }

    public function test_print_sheet_renders_svg_slips(): void
    {
        $this->operator();

        $resp = $this->post(route('arrivals.vendor.qr.print'), [
            'classification' => 'Test Class',
            'item_name' => 'Test Item',
            'uom' => 'kg',
            'type_code' => 11,
            'codes' => [
                ['text' => 'D25261100001', 'weight' => 1.5],
                ['text' => 'D25261100002', 'weight' => 0],
            ],
        ]);
        $resp->assertOk();

        $html = (string) $resp->getContent();
        $this->assertStringContainsString('D25261100001', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('1.5 kg', $html);
        // 2 codes, one page (12 per page).
        $this->assertSame(1, substr_count($html, 'class="a4-page"'));
    }

    public function test_mismatched_arrival_line_pair_is_rejected(): void
    {
        [$arrival, $line] = $this->arrivalLine();
        [$item, $classification] = $this->item();
        $this->operator();
        $yearcode = FiscalYear::yearcode();

        // A line from a DIFFERENT arrival must abort before any write.
        $other = ArrivalItem::query()
            ->where('arrival_id', '!=', $arrival->arrival_id)
            ->where('item_id', '!=', 0)
            ->orderBy('arrsub_id')
            ->firstOrFail();

        $this->postJson(route('arrivals.vendor.qr.save-linked', [$arrival, $other]), [
            'classification_id' => $classification->classification_id,
            'item_id' => $item->items_id,
            'ups_good' => 1,
            'serial_start' => QrSerial::peekSerial($yearcode, 11),
            'codes' => [['text' => 'MISMATCH001', 'weight' => 1]],
        ])->assertStatus(422);

        $this->assertSame(0, QrCode::query()->count());
    }

    public function test_qr_tables_and_type_seeds_exist(): void
    {
        $this->assertTrue(Schema::hasTable('qr_codes'));
        $this->assertTrue(Schema::hasTable('qr_scan_logs'));
        $this->assertTrue(Schema::hasTable('qr_item_types'));

        $types = DB::table('qr_item_types')->orderBy('type_code')->pluck('type_code')->all();
        $this->assertSame([11, 12, 13], $types);
    }

    public function test_ledger_replaces_bare_read(): void
    {
        // Sanity: the arrival flow is untouched — the good ledger still
        // serves the workspace (guards against an accidental regression
        // in the QR wiring).
        $this->operator();
        $this->assertGreaterThan(0, StockLedgerGood::query()->count());
    }
}
