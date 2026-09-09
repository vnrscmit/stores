<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 5: verify row-count parity legacy vs final.
 *
 * Expected legacy count = number of DISTINCT primary-key groups in staging
 * (the live legacy DB contains duplicate-PK rows — MyISAM ran without
 * enforced keys — and only the first occurrence is preserved by design,
 * audited in integrity_fixes_log / migrate-data output). For tables without
 * a PK the raw staging count is compared.
 *
 * Exits non-zero when any table diverges beyond the duplicate-PK rule.
 */
class LegacyVerify extends Command
{
    protected $signature = 'legacy:verify
                            {--json= : Optional path to write the verification report JSON}';

    protected $description = 'Verify row-count parity between legacy and migrated tables';

    public function handle(): int
    {
        $map = config('legacy-map-tables');
        $failures = 0;
        $report = [];
        $rows = [];

        foreach ($map as $legacyTable => $conf) {
            if (! empty($conf['ignore'])) {
                $rows[] = [$legacyTable, '(ignored)', '-', 'SKIP'];

                continue;
            }

            if (! empty($conf['consolidate'])) {
                $count = (int) DB::table('users')->count();
                $rows[] = [$legacyTable.' (+3 sibling tables)', 'users', number_format($count).' consolidated', 'INFO'];

                continue;
            }

            $final = $conf['new_name'];
            $staging = 'legacy_'.$legacyTable;

            if (! Schema::hasTable($staging) || ! Schema::hasTable($final)) {
                $rows[] = [$legacyTable, $final, '-', 'MISSING'];
                $report[$legacyTable] = ['status' => 'MISSING'];
                $failures++;

                continue;
            }

            // PK of the final table -> expected = DISTINCT groups in staging.
            $pkCols = collect(Schema::getIndexes($final))
                ->first(fn ($ix) => $ix['name'] === 'primary')['columns'] ?? [];
            $stagingCols = array_flip(Schema::getColumnListing($staging));
            $pkCols = array_values(array_intersect($pkCols, array_keys($stagingCols)));

            if ($pkCols !== []) {
                $distinctList = implode(', ', array_map(fn ($c) => "`{$c}`", $pkCols));
                $expected = (int) DB::table($staging)
                    ->selectRaw("COUNT(DISTINCT {$distinctList}) AS c")
                    ->value('c');
                $dupeRule = 'distinct-pk';
            } else {
                $expected = (int) DB::table($staging)->count();
                $dupeRule = 'raw';
            }

            // Placeholder masters created by legacy:integrity-fix are expected
            // additions (audited) — they pad the final count above staging.
            $placeholders = Schema::hasTable('integrity_fixes_log')
                ? (int) DB::table('integrity_fixes_log')
                    ->where('action', 'placeholder')
                    ->where('table_name', $final)
                    ->count()
                : 0;
            $expected += $placeholders;

            $finalCount = (int) DB::table($final)->count();

            $status = $expected === $finalCount ? 'PASS' : 'FAIL';
            if ($status === 'FAIL') {
                $failures++;
            }

            $report[$legacyTable] = [
                'legacy' => $expected,
                'legacy_raw' => $dupeRule === 'raw' ? (int) DB::table($staging)->count() : $expected - $placeholders,
                'placeholders' => $placeholders,
                'final' => $finalCount,
                'rule' => $dupeRule,
                'status' => $status,
            ];
            $rows[] = [$legacyTable, $final, number_format($finalCount).' / '.number_format($expected), $status];
        }

        $this->table(['legacy', 'final', 'rows final/legacy(expected)', 'status'], $rows);

        if (Schema::hasTable('integrity_fixes_log')) {
            $placeholders = (int) DB::table('integrity_fixes_log')->where('action', 'placeholder')->count();
            $nulled = (int) DB::table('integrity_fixes_log')->where('action', 'nulled')->count();
            $this->info('Integrity fixes: '.number_format($placeholders).' placeholders, '.number_format($nulled).' nulled refs');
        }

        $users = (int) DB::table('users')->count();
        $this->info('Consolidated users: '.number_format($users));

        if ($this->option('json')) {
            file_put_contents($this->option('json'), json_encode([
                'checked_at' => now()->toIso8601String(),
                'failures' => $failures,
                'tables' => $report,
            ], JSON_PRETTY_PRINT));
        }

        if ($failures > 0) {
            $this->error("PARITY FAILURES: {$failures}");

            return self::FAILURE;
        }

        $this->info('All row counts match legacy (distinct-PK rule where applicable).');

        return self::SUCCESS;
    }
}
