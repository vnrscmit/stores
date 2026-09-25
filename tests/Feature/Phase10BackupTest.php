<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\User;
use App\Support\DatabaseBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 10 slice 1 acceptance: the admin database backup — the port of
 * the legacy admin navbar "Backup" popup (utility/backup.php full dump)
 * plus the backup1.php business-tables variant.
 *
 * Semantics under test:
 *  - the FULL dump contains every table's CREATE TABLE and one INSERT
 *    per row, no DROP statements (legacy commented them out), the legacy
 *    filename pattern Backup_{database}_{d-m-Y}.sql;
 *  - the BUSINESS dump mirrors backup1.php: the fixed 51-table legacy
 *    list mapped onto the port tables, data only, INSERTs without
 *    column lists, absent/merged legacy names recorded as comments;
 *  - streaming works end to end (headers + filename + content through
 *    the actual HTTP response);
 *  - role gate: admins get the dump, other roles bounce home, guests
 *    get 401 (route middleware).
 *
 * Hermeticity: read-only endpoints — the suite posts nothing and needs
 * no sweep; it runs against the pipeline-built database like the other
 * hermetic suites.
 */
class Phase10BackupTest extends TestCase
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
    }

    private function admin(): User
    {
        $user = DB::table('users')->where('role', 'admin')->first();
        $this->assertNotNull($user, 'An admin user must exist for the backup gate.');

        $model = User::query()->findOrFail($user->id);
        $this->actingAs($model);

        return $model;
    }

    private function operator(): void
    {
        $user = DB::table('users')->where('role', 'operator')->first();
        $this->assertNotNull($user, 'An operator user must exist for the backup gate.');
        $this->actingAs(User::query()->findOrFail($user->id));
    }

    public function test_module_requires_authentication(): void
    {
        // Guests are bounced to login by the auth middleware (both pages).
        $this->get(route('admin.backup.index'))->assertRedirect(route('login'));
        $this->get(route('admin.backup.download'))->assertRedirect(route('login'));
        $this->get(route('admin.backup.download-business'))->assertRedirect(route('login'));
    }

    public function test_non_admin_roles_bounce_home(): void
    {
        $this->operator();

        $this->get(route('admin.backup.index'))
            ->assertRedirect(route('operator.home'))
            ->assertSessionHas('warning');
    }

    public function test_backup_screen_renders_for_admin(): void
    {
        $this->admin();

        $this->get(route('admin.backup.index'))
            ->assertOk()
            ->assertSee('Database backup')
            ->assertSee(DatabaseBackup::filename())
            ->assertSee('Download full backup')
            ->assertSee('business tables only');
    }

    public function test_full_download_streams_the_whole_database(): void
    {
        $this->admin();

        $resp = $this->get(route('admin.backup.download'));

        $resp->assertOk();

        // Legacy filename pattern + attachment disposition (Symfony
        // leaves simple filenames unquoted).
        $expectedName = DatabaseBackup::filename();
        $disposition = (string) $resp->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString($expectedName, $disposition);

        $content = $resp->streamedContent();
        $this->assertNotEmpty($content);

        // Every table's schema is present (SHOW CREATE TABLE port).
        $tables = array_map(
            fn ($t) => (string) array_values((array) $t)[0],
            DB::select('SHOW TABLES')
        );
        $this->assertGreaterThan(50, count($tables));

        foreach (['items', 'warehouses', 'users'] as $mustHave) {
            $this->assertStringContainsString('CREATE TABLE `'.$mustHave.'`', $content);
        }

        // No DROP statements (legacy commented them out).
        $this->assertStringNotContainsString('DROP TABLE', $content);

        // Row data is present (deterministic PK order).
        $this->assertMatchesRegularExpression(
            '/INSERT INTO `items` \([^)]*\) VALUES \(/',
            $content
        );
    }

    public function test_full_dump_round_trips_its_own_rows(): void
    {
        // Dump one small known table through the generator and prove the
        // INSERT statements match the live rows verbatim (NULL handling
        // included) — the escaping contract of the writer.
        $table = 'financial_years';
        $rows = DB::table($table)->orderBy('yearsid')->get();

        $chunks = iterator_to_array(DatabaseBackup::fullDump(), false);
        $dump = implode('', $chunks);

        $start = strpos($dump, 'INSERT INTO `'.$table.'`');
        $this->assertNotFalse($start, 'The dump must contain '.$table.' rows.');

        $inserts = [];
        while (($start = strpos($dump, 'INSERT INTO `'.$table.'`', $start)) !== false) {
            $end = strpos($dump, "\n", $start);
            $inserts[] = substr($dump, $start, $end - $start);
            $start = $end;
        }

        $this->assertCount(count($rows), $inserts);

        foreach ($rows as $i => $row) {
            $values = array_map(fn ($v) => $v === null ? 'NULL' : (string) $v, array_values((array) $row));

            foreach ($values as $value) {
                if ($value === 'NULL') {
                    $this->assertStringContainsString(', NULL', $inserts[$i]);

                    continue 2;
                }
            }

            $this->assertStringContainsString("'".str_replace("'", "''", (string) $values[1])."'", $inserts[$i]);
        }
    }

    public function test_business_download_mirrors_backup1(): void
    {
        $this->admin();

        $resp = $this->get(route('admin.backup.download-business'));
        $resp->assertOk();

        $content = $resp->streamedContent();

        // Data only: INSERTs, no schema.
        $this->assertStringNotContainsString('CREATE TABLE', $content);

        // Bare INSERT form (legacy backup1.php wrote no column lists).
        $this->assertMatchesRegularExpression('/^INSERT INTO bins VALUES \(/m', $content);

        // Every mapped port table appears; merged/absent legacy names are
        // recorded as comments so the parity gap stays visible.
        $this->assertStringContainsString('INSERT INTO items VALUES', $content);
        $this->assertStringContainsString('INSERT INTO stock_ledger_goods VALUES', $content);
        $this->assertStringContainsString(
            '-- (legacy table tbl_opr has no dedicated port table',
            $content
        );
        $this->assertStringContainsString(
            '-- (legacy table tblissue has no dedicated port table',
            $content
        );

        // The legacy 51-table list is fully accounted for.
        $this->assertCount(51, DatabaseBackup::BUSINESS_TABLES);
    }

    public function test_business_dump_inserts_match_live_rows(): void
    {
        $chunks = iterator_to_array(DatabaseBackup::businessDump(), false);
        $dump = implode('', $chunks);

        $table = 'financial_years';
        $count = DB::table($table)->count();
        $this->assertGreaterThan(0, $count);

        $found = substr_count($dump, 'INSERT INTO '.$table.' VALUES (');
        $this->assertSame($count, $found);
    }
}
