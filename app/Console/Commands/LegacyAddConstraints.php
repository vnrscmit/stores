<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 4: enforce foreign keys (idempotent) from config/legacy-map-relations.
 * Ineligible pairs (polymorphic/text-typed) remain indexed only, per the map.
 */
class LegacyAddConstraints extends Command
{
    protected $signature = 'legacy:add-constraints';

    protected $description = 'Add foreign key constraints from the relation map (idempotent)';

    public function handle(): int
    {
        $relations = config('legacy-map-relations');
        $added = 0;
        $skipped = 0;

        foreach ($relations['eligible'] as $rel) {
            $child = $rel['child'];
            $column = $rel['column'];
            $parent = $rel['parent'];
            $parentKey = $rel['parentKey'];

            if (! Schema::hasTable($child) || ! Schema::hasTable($parent)) {
                $this->warn("  - {$child}.{$column}: table missing");

                continue;
            }

            $fkName = "fk_{$child}_{$column}";

            $exists = DB::selectOne(
                "SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND CONSTRAINT_NAME = ?
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$fkName]
            );

            if ((int) $exists->c > 0) {
                $skipped++;

                continue;
            }

            // Constraint tolerance: make the column exactly the parent PK type
            // and ensure it is indexed with a stable name.
            try {
                $type = $this->parentColumnType($parent, $parentKey);
                DB::statement("ALTER TABLE `{$child}` MODIFY `{$column}` {$type} NULL");
                $indexName = "ix_fk_{$child}_{$column}";
                $hasIndex = DB::selectOne(
                    'SELECT COUNT(*) AS c FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$child, $indexName]
                );
                if ((int) $hasIndex->c === 0) {
                    DB::statement("ALTER TABLE `{$child}` ADD INDEX `{$indexName}` (`{$column}`)");
                }

                $onDelete = $rel['on-delete'] ?? 'restrict';
                DB::statement("ALTER TABLE `{$child}` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$column}`) REFERENCES `{$parent}`(`{$parentKey}`) ON DELETE {$onDelete}");

                $added++;
                $this->line("  + {$fkName}");
            } catch (\Throwable $e) {
                $this->warn("  ! {$fkName} failed: ".mb_strimwidth($e->getMessage(), 0, 160, '…'));
                $skipped++;
            }
        }

        $this->info("Constraints: {$added} added, {$skipped} skipped/already present.");

        return self::SUCCESS;
    }

    private function parentColumnType(string $table, string $column): string
    {
        $col = DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        if ($col === null) {
            throw new \RuntimeException("Parent column {$table}.{$column} not found");
        }

        return $col->COLUMN_TYPE; // e.g. int unsigned
    }
}
