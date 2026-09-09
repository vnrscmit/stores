<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 3: resolve dangling references WITHOUT destroying history.
 *
 * --strategy=placeholder  restore deleted master rows as "[legacy-missing #ID]"
 *                         placeholders so historical movements stay joinable
 *                         (every insert audited in integrity_fixes_log).
 * --strategy=null         NULL-out any remaining unresolvable references
 *                         (audit-logged).
 * --strategy=dry          report only, change nothing.
 */
class LegacyIntegrityFix extends Command
{
    protected $signature = 'legacy:integrity-fix
                            {--strategy=placeholder : placeholder | null | dry}';

    protected $description = 'Resolve dangling references: audited placeholder masters or null-out';

    private const MASTER_TABLES = [
        'warehouses' => ['pk' => 'whid', 'name' => 'perticulars'],
        'bins' => ['pk' => 'binid', 'name' => 'binname'],
        'sub_bins' => ['pk' => 'sid', 'name' => 'sname'],
        'classifications' => ['pk' => 'classification_id', 'name' => 'classification'],
        'items' => ['pk' => 'items_id', 'name' => 'stores_item'],
        'parties' => ['pk' => 'p_id', 'name' => 'business_name'],
    ];

    /** child table -> [fk column => [master, master name column]] scanned for dangling refs. */
    private const REFERENCES = [
        'issue_slocs' => [
            'whid' => ['warehouses', 'perticulars'],
            'binid' => ['bins', 'binname'],
            'subbin' => ['sub_bins', 'sname'],
            'item_id' => ['items', 'stores_item'],
        ],
        'arrival_slocs' => [
            'whid' => ['warehouses', 'perticulars'],
            'binid' => ['bins', 'binname'],
            'subbin' => ['sub_bins', 'sname'],
            'item_id' => ['items', 'stores_item'],
        ],
        'e_indent_items' => [
            'classification_id' => ['classifications', 'classification'],
            'items_id' => ['items', 'stores_item'],
        ],
        'issue_items' => [
            'item_id' => ['items', 'stores_item'],
        ],
        'arrival_items' => [
            'item_id' => ['items', 'stores_item'],
        ],
        'dtog_items' => [
            'whid' => ['warehouses', 'perticulars'],
            'binid' => ['bins', 'binname'],
            'subbinid' => ['sub_bins', 'sname'],
        ],
        'gtod_items' => [
            'whid' => ['warehouses', 'perticulars'],
            'binid' => ['bins', 'binname'],
            'subbinid' => ['sub_bins', 'sname'],
        ],
        'excess_items' => [
            'whid' => ['warehouses', 'perticulars'],
            'binid' => ['bins', 'binname'],
            'subbinid' => ['sub_bins', 'sname'],
        ],
        'sloc_items' => [
            'whid' => ['warehouses', 'perticulars'],
            'binid' => ['bins', 'binname'],
            'subbinid' => ['sub_bins', 'sname'],
        ],
        'stock_ledger_goods' => [
            'stlg_tritemid' => ['items', 'stores_item'],
        ],
        'stock_ledger_damages' => [
            'stld_tritemid' => ['items', 'stores_item'],
        ],
    ];

    /** Detail -> header references (nullable strategy only: no master to restore). */
    private const HEADER_REFS = [
        ['issue_items', 'issue_id', 'issues', 'issue_id'],
        ['issue_slocs', 'issue_tr_id', 'issues', 'issue_id'],
        ['arrival_items', 'arrival_id', 'arrivals', 'arrival_id'],
        ['arrival_slocs', 'arr_tr_id', 'arrivals', 'arrival_id'],
        ['e_indent_items', 'id_in', 'e_indents', 'tid'],
    ];

    public function handle(): int
    {
        $strategy = strtolower($this->option('strategy'));
        if (! in_array($strategy, ['placeholder', 'null', 'dry'], true)) {
            $this->error('Unknown strategy: '.$strategy);

            return self::FAILURE;
        }

        $this->ensureAuditTable();

        // 1. Master references (placeholder-able).
        foreach (self::REFERENCES as $child => $refs) {
            if (! Schema::hasTable($child)) {
                continue;
            }
            foreach ($refs as $col => [$master, $nameCol]) {
                if (! Schema::hasColumn($child, $col)) {
                    continue;
                }
                $pk = self::MASTER_TABLES[$master]['pk'];

                $dangling = DB::table($child)
                    ->selectRaw("DISTINCT `{$col}` AS id")
                    ->whereNotNull($col)
                    ->where($col, '!=', 0)
                    ->whereNotIn($col, DB::table($master)->select($pk))
                    ->pluck('id');

                foreach ($dangling as $id) {
                    $this->fixMasterReference($strategy, $child, $col, $master, $pk, $nameCol, (int) $id);
                }
            }
        }

        // 2. Detail -> header references (null-out only).
        foreach (self::HEADER_REFS as [$child, $col, $parent, $parentCol]) {
            if (! Schema::hasTable($child)) {
                continue;
            }

            $dangling = DB::table($child)
                ->selectRaw("DISTINCT `{$col}` AS id")
                ->whereNotNull($col)
                ->whereNotIn($col, DB::table($parent)->select($parentCol))
                ->pluck('id');

            foreach ($dangling as $id) {
                if ($strategy === 'dry') {
                    $this->line("  ? {$child}.{$col}={$id} -> {$parent} missing");

                    continue;
                }

                if ($strategy === 'null') {
                    DB::table($child)->where($col, $id)->update([$col => null]);
                    $this->logFix($child, $col, (int) $id, 'nulled');
                    $this->line("  ~ {$child}.{$col}={$id} -> NULL");
                }

                if ($strategy === 'placeholder') {
                    // No master row exists to restore; report for review.
                    $this->line("  ! {$child}.{$col}={$id} -> {$parent} missing (run --strategy=null to clear)");
                }
            }
        }

        $this->info('Integrity fix complete ('.$strategy.').');

        return self::SUCCESS;
    }

    private function fixMasterReference(
        string $strategy,
        string $child,
        string $col,
        string $master,
        string $pk,
        string $nameCol,
        int $id
    ): void {
        if ($strategy === 'dry') {
            $this->line("  ? {$child}.{$col}={$id} -> {$master} missing");

            return;
        }

        if ($strategy === 'placeholder') {
            $exists = DB::table($master)->where($pk, $id)->exists();
            if ($exists) {
                return;
            }
            DB::table($master)->insert([
                $pk => $id,
                $nameCol => "[legacy-missing #{$id}]",
            ]);
            $this->logFix($master, $pk, $id, 'placeholder');
            $this->line("  + {$master} #{$id} placeholder created");

            return;
        }

        // null strategy: clear every child column pointing at this master id.
        $cleared = 0;
        foreach (self::REFERENCES as $childTable => $refs) {
            if (! Schema::hasTable($childTable)) {
                continue;
            }
            foreach ($refs as $childCol => [$m, $n]) {
                if ($m === $master && Schema::hasColumn($childTable, $childCol)) {
                    $cleared += DB::table($childTable)
                        ->where($childCol, $id)
                        ->update([$childCol => null]);
                }
            }
        }
        $this->logFix($master, $pk, $id, 'nulled');
        $this->line("  ~ {$cleared} references to {$master} #{$id} nulled");
    }

    private function ensureAuditTable(): void
    {
        if (! Schema::hasTable('integrity_fixes_log')) {
            Schema::create('integrity_fixes_log', function ($t) {
                $t->id();
                $t->string('table_name', 100);
                $t->string('column_name', 100);
                $t->string('ref_id', 50);
                $t->string('action', 50);
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    private function logFix(string $table, string $column, int $id, string $action): void
    {
        DB::table('integrity_fixes_log')->insert([
            'table_name' => $table,
            'column_name' => $column,
            'ref_id' => (string) $id,
            'action' => $action,
            'created_at' => now(),
        ]);
    }
}
