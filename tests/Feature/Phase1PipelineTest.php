<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 acceptance: the migration pipeline (schema + stage + transform +
 * integrity + constraints) runs green against the test database.
 */
class Phase1PipelineTest extends TestCase
{
    /** Heavy pipeline runs once per PHP process, not once per test method. */
    private static bool $pipelineReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$pipelineReady) {
            return;
        }

        // Run the real pipeline once against the test DB, over the reduced
        // test subset (--limit keeps big legacy tables capped and their
        // detail rows FK-coherent — see LegacyStageImport::copySubset).
        // Idempotent: staging is dropped/recreated, final tables truncated
        // before insert.
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->artisan('legacy:stage-import', ['--limit' => (string) LegacyStageImport::TEST_SUBSET_LIMIT]);
        $this->artisan('legacy:migrate-data');
        $this->artisan('legacy:integrity-fix', ['--strategy' => 'placeholder']);
        $this->artisan('legacy:add-constraints');

        self::$pipelineReady = true;
    }

    /** @test */
    public function final_schema_matches_generated_migrations(): void
    {
        foreach (['users', 'items', 'warehouses', 'bins', 'sub_bins', 'e_indents', 'issues', 'stock_ledger_goods'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "table {$t} missing");
        }
    }

    /** @test */
    public function row_counts_match_staging(): void
    {
        $map = config('legacy-map-tables');

        $checked = 0;
        foreach ($map as $legacyTable => $conf) {
            if (! empty($conf['ignore']) || ! empty($conf['consolidate'])) {
                continue;
            }
            $staging = 'legacy_'.$legacyTable;
            $final = $conf['new_name'];
            if (! Schema::hasTable($staging) || ! Schema::hasTable($final)) {
                continue;
            }

            // Mirror legacy:verify's parity rule: legacy rows duplicated on a
            // PK (MyISAM never enforced keys) count once per distinct group.
            $pkCols = collect(Schema::getIndexes($final))
                ->first(fn ($ix) => $ix['name'] === 'primary')['columns'] ?? [];
            $pkCols = array_values(array_intersect($pkCols, Schema::getColumnListing($staging)));

            if ($pkCols !== []) {
                $distinctList = implode(', ', array_map(fn ($c) => "`{$c}`", $pkCols));
                $legacyCount = (int) DB::table($staging)
                    ->selectRaw("COUNT(DISTINCT {$distinctList}) AS c")
                    ->value('c');
            } else {
                $legacyCount = (int) DB::table($staging)->count();
            }

            // integrity-fix placeholders are expected additions.
            $expectedPlaceholders = Schema::hasTable('integrity_fixes_log')
                ? (int) DB::table('integrity_fixes_log')
                    ->where('action', 'placeholder')
                    ->where('table_name', $final)
                    ->count()
                : 0;

            $finalCount = (int) DB::table($final)->count();
            $this->assertSame(
                $legacyCount + $expectedPlaceholders,
                $finalCount,
                "row parity failed for {$final}"
            );

            $checked++;
            if ($checked >= 10) {
                break; // representative sample keeps the suite fast
            }
        }

        $this->assertGreaterThan(5, $checked);
    }

    /** @test */
    public function auth_consolidation_is_sane(): void
    {
        $users = DB::table('users')->count();
        $this->assertGreaterThan(0, $users);

        // All migrated passwords are bcrypt.
        $plaintext = DB::table('users')->where('password', 'not like', '$2y$%')->count();
        $this->assertSame(0, $plaintext);

        // All four legacy roles represented.
        foreach (['admin', 'operator', 'eindent', 'viewer'] as $role) {
            $this->assertGreaterThan(0, DB::table('users')->where('role', $role)->count(), "role {$role} missing");
        }
    }

    /** @test */
    public function foreign_keys_are_enforced(): void
    {
        $fks = (int) DB::selectOne(
            "SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        )->c;

        $this->assertGreaterThan(0, $fks, 'no FK constraints were created');

        // FK enforcement actually rejects a bad reference.
        $this->expectException(QueryException::class);
        DB::table('bins')->insert(['binid' => 999901, 'whid' => 424242, 'binname' => 'ORPHAN']);
    }
}
