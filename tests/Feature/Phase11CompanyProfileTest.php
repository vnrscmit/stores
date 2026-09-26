<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\User;
use App\Support\QrSerial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 11 slice 2 acceptance: the company profile editor — the port
 * of the legacy Masters "Parameter" screens (add_company.php /
 * edit_company.php) over the single company_settings row id=41.
 *
 * Semantics under test:
 *  - the single-row edit/update with the legacy field set (company +
 *    plant address blocks, licence/TIN/CST numbers, legacy maxlengths);
 *  - plantcode: the legacy screens edited it but the live legacy
 *    schema lacked the column (the edits silently vanished) — the
 *    port adds it (migration 000055) and really persists it;
 *  - the QR contract: QrSerial::plantCode() reads the column with the
 *    legacy 'DEF' fallback (regression guard — the pre-slice code read
 *    the multi-line `plant` address instead, which would have corrupted
 *    every QR prefix on a fully migrated database);
 *  - admin-only gate + audit row (module admin.company).
 */
class Phase11CompanyProfileTest extends TestCase
{
    private static bool $pipelineReady = false;

    /** The captured baseline of the shared company_settings row. */
    private array $baseline = [];

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
    }

    private function admin(): User
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    private function captureBaseline(): void
    {
        $row = CompanySetting::query()->where('id', 41)->firstOrFail();
        $this->baseline = $row->getAttributes();
    }

    private function restoreBaseline(): void
    {
        if ($this->baseline === []) {
            return;
        }

        DB::table('company_settings')->where('id', 41)->update($this->baseline);
        $this->baseline = [];
    }

    private function sweep(): void
    {
        AuditLog::query()->where('module', 'admin.company')->delete();
        $this->restoreBaseline();
    }

    public function test_edit_screen_requires_admin(): void
    {
        $this->get(route('admin.company.edit'))->assertRedirect(route('login'));

        $operator = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($operator);

        $this->get(route('admin.company.edit'))
            ->assertRedirect(route('operator.home'))
            ->assertSessionHas('warning');

        $this->put(route('admin.company.update'), ['company_name' => 'X'])
            ->assertRedirect(route('operator.home'));
    }

    public function test_edit_screen_renders_the_profile(): void
    {
        $this->admin();
        $this->captureBaseline();

        $company = CompanySetting::query()->where('id', 41)->firstOrFail();

        $this->get(route('admin.company.edit'))
            ->assertOk()
            ->assertSee('Company profile')
            ->assertSee('QR')
            ->assertSee((string) $company->company_name, false);
    }

    public function test_update_persists_the_profile_and_audits(): void
    {
        $admin = $this->admin();
        $this->captureBaseline();

        try {
            $this->put(route('admin.company.update'), [
                'company_name' => 'VNR SEEDS PVT. LTD.',
                'address' => "VSPL Plant\nAhiwara-Dhamda Road",
                'ccity' => 'Dhamda',
                'cpin' => '490021',
                'cstate' => 'Chhattisgarh',
                'cstd' => '788',
                'cphone' => '788222333',
                'cphone1' => '',
                'plant' => 'VSPL Plant',
                'plantcode' => 'D25',
                'pcity' => 'Dhamda',
                'ppin' => '490021',
                'pstate' => 'Chhattisgarh',
                'pstd' => '788',
                'pphone' => '788333444',
                'pphone1' => '',
                'licence_no' => 'LIC-2026',
                'tin' => '12345678901',
                'cst_no' => 'CS-9876',
            ])->assertRedirect(route('admin.company.edit'))
                ->assertSessionHas('status');

            $company = CompanySetting::query()->where('id', 41)->firstOrFail();
            $this->assertSame('Dhamda', (string) $company->ccity);
            $this->assertSame('D25', (string) $company->plantcode);
            $this->assertSame('LIC-2026', (string) $company->licence_no);
            $this->assertSame('12345678901', (string) $company->tin);
            $this->assertSame('CS-9876', (string) $company->cst_no);

            $this->assertDatabaseHas('audit_logs', [
                'module' => 'admin.company',
                'action' => 'update',
                'record_type' => 'company_settings',
                'record_id' => 41,
                'user_id' => $admin->getKey(),
            ]);
        } finally {
            $this->sweep();
        }
    }

    public function test_update_validates_the_legacy_field_set(): void
    {
        $this->admin();
        $this->captureBaseline();

        // Company name is required (legacy's mandatory field).
        $this->put(route('admin.company.update'), ['company_name' => ''])
            ->assertSessionHasErrors('company_name');

        // The plant code is a single token: it prefixes every QR code.
        $this->put(route('admin.company.update'), [
            'company_name' => 'VNR SEEDS PVT. LTD.',
            'plantcode' => 'D 25',
        ])->assertSessionHasErrors('plantcode');

        // Legacy maxlengths hold server-side.
        $this->put(route('admin.company.update'), [
            'company_name' => 'VNR SEEDS PVT. LTD.',
            'tin' => '1234567890123456789012345',
        ])->assertSessionHasErrors('tin');

        $this->assertSame('VNR SEEDS PVT. LTD.', (string) CompanySetting::query()->where('id', 41)->value('company_name'));
    }

    public function test_plant_code_feeds_the_qr_prefix(): void
    {
        $this->admin();
        $this->captureBaseline();

        // Baseline: the migrated row carries no plantcode (the legacy
        // schema never had the column) — the legacy DEF fallback holds.
        $this->assertSame('DEF', QrSerial::plantCode());

        try {
            $this->put(route('admin.company.update'), [
                'company_name' => 'VNR SEEDS PVT. LTD.',
                'plantcode' => 'D25',
            ])->assertRedirect(route('admin.company.edit'));

            $this->assertSame('D25', QrSerial::plantCode());
            $this->assertSame('D25252611', QrSerial::prefix('25-26', 11));

            // Clearing it restores the fallback.
            $this->put(route('admin.company.update'), [
                'company_name' => 'VNR SEEDS PVT. LTD.',
                'plantcode' => '',
            ])->assertRedirect(route('admin.company.edit'));

            $this->assertSame('DEF', QrSerial::plantCode());
        } finally {
            $this->sweep();
        }
    }

    protected function tearDown(): void
    {
        $this->sweep();

        parent::tearDown();
    }
}
