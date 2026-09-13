<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 1 of the pipeline: raw verbatim copy legacy.* -> legacy_* staging
 * tables (relaxed sql_mode session; nothing transformed). The legacy database
 * is only ever read.
 *
 * Column lists and primary keys are introspected from the LIVE legacy
 * database (authoritative), not the dump audit — the two can drift.
 *
 * Staging tables are generic: an auto-increment staging id plus one TEXT
 * column per legacy column, so any legacy value — zero dates, sentinel '0',
 * latin1 bytes — lands unmodified.
 */
class LegacyStageImport extends Command
{
    /**
     * Row cap the test suites pass as --limit to build the reduced,
     * FK-coherent legacy dataset instead of the full ~420k-row pipeline.
     */
    public const TEST_SUBSET_LIMIT = 2000;

    protected $signature = 'legacy:stage-import
                            {--tables= : Comma-separated subset of legacy tables}
                            {--limit= : Cap large tables to their first N primary-key rows (test subset)}';

    protected $description = 'Copy legacy tables verbatim into legacy_* staging tables';

    public function handle(): int
    {
        $legacyDb = config('database.connections.legacy.database');
        $map = config('legacy-map-tables');

        $tables = $this->option('tables')
            ? explode(',', $this->option('tables'))
            : array_keys($map);

        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $imported = 0;
        foreach ($tables as $legacyTable) {
            $conf = $map[$legacyTable] ?? null;
            if ($conf === null || ! empty($conf['ignore'])) {
                continue;
            }

            $live = $this->liveColumns($legacyTable);
            if ($live === null) {
                $this->warn("  - {$legacyTable}: not present in legacy DB, skipped");

                continue;
            }
            [$columns, $pk] = $live;

            $staging = 'legacy_'.$legacyTable;

            // Subset mode: a capped parent already staged this child filtered
            // to its kept headers — re-copying it in full would dangle.
            if ($limit !== null && Schema::hasTable($staging)) {
                $this->line("  = {$legacyTable}: already staged with its capped parent");

                continue;
            }

            Schema::dropIfExists($staging);
            Schema::create($staging, function ($t) use ($columns) {
                $t->increments('stage_id');
                foreach ($columns as $col) {
                    $t->text($col)->nullable();
                }
            });

            $count = 0;
            if ($pk !== null) {
                $count += $this->copyPaged($legacyTable, $staging, $columns, $pk, $limit);
            } else {
                $rows = DB::connection('legacy')->table($legacyTable)->select($columns)->get();
                $count = $this->bulkInsert($staging, $rows);
            }

            $imported++;
            $this->line("  + {$legacyTable} -> {$staging} (".number_format($count).' rows)');
        }

        $this->info("Staged {$imported} tables from {$legacyDb}.");

        return self::SUCCESS;
    }

    /**
     * Copy a paged table, optionally capped to a test subset.
     *
     * Without $limit this is the verbatim full copy. With --limit=N (test
     * datasets only), tables larger than N keep their first N primary-key
     * rows, and every detail table that references them (per the relation
     * map) keeps the rows belonging to those headers, so the subset stays
     * referentially coherent: children never dangle onto unstaged headers.
     * Small tables (auth, masters, years) copy in full because they fit.
     */
    private function copyPaged(string $legacyTable, string $staging, array $columns, string $pk, ?int $limit): int
    {
        $conn = DB::connection('legacy');

        if ($limit !== null && $limit > 0) {
            $total = (int) $conn->table($legacyTable)->count();

            if ($total > $limit) {
                return $this->copySubset($legacyTable, $staging, $columns, $pk, $limit);
            }
        }

        $max = (int) $conn->table($legacyTable)->max($pk);
        $count = 0;
        for ($start = 0; $start <= $max; $start += 2000) {
            $rows = $conn->table($legacyTable)
                ->whereBetween($pk, [$start, $start + 2000])
                ->select($columns)
                ->get();
            $count += $this->bulkInsert($staging, $rows);
        }

        return $count;
    }

    /**
     * Test-subset copy: cap this header table to the first N PK rows, then
     * re-copy every child table in the relation map that references it,
     * filtered to rows whose FK lands on a kept header (NULL FKs kept —
     * they reference nothing). Children are copied here so the caller's
     * own loop must not double-stage them; their map entries become no-ops
     * because their staging table already exists.
     */
    private function copySubset(string $legacyTable, string $staging, array $columns, string $pk, int $limit): int
    {
        $conn = DB::connection('legacy');

        $boundary = $conn->table($legacyTable)->orderBy($pk)->limit(1)->offset($limit - 1)->value($pk);
        if ($boundary === null) {
            $boundary = $conn->table($legacyTable)->max($pk);
        }

        $keptIds = $conn->table($legacyTable)->where($pk, '<=', $boundary)->orderBy($pk)->pluck($pk)->all();

        $count = 0;
        for ($start = 0; $start <= (int) $boundary; $start += 2000) {
            $rows = $conn->table($legacyTable)
                ->whereBetween($pk, [$start, min($start + 1999, (int) $boundary)])
                ->select($columns)
                ->get();
            $count += $this->bulkInsert($staging, $rows);
        }

        $this->line("  ~ {$legacyTable}: capped subset ({$limit} of ".number_format((int) $conn->table($legacyTable)->count())." rows)");

        // Children referencing this header, in map order. The relation map
        // speaks in FINAL table names; resolve both sides back to the legacy
        // names the staging pass works with. The map's per-pair 'when'
        // filters are ignored here: rows failing them carry no enforced FK,
        // so keeping them is as safe as in the full import.
        $finalParent = config("legacy-map-tables.{$legacyTable}.new_name");

        foreach (config('legacy-map-relations.eligible') as $rel) {
            if (($rel['parent'] ?? null) !== $finalParent) {
                continue;
            }

            $childLegacy = null;
            foreach (config('legacy-map-tables') as $legacyName => $childConf) {
                if (($childConf['new_name'] ?? null) === $rel['child']) {
                    $childLegacy = $legacyName;

                    break;
                }
            }

            if ($childLegacy === null || ! empty(config("legacy-map-tables.{$childLegacy}.ignore"))) {
                continue;
            }

            $childLive = $this->liveColumns($childLegacy);
            if ($childLive === null) {
                continue;
            }
            [$childColumns, $childPk] = $childLive;

            $fk = $rel['column'];
            if (! in_array($fk, $childColumns, true)) {
                continue;
            }

            $childStaging = 'legacy_'.$childLegacy;
            if (Schema::hasTable($childStaging)) {
                $this->line("  = {$childLegacy}: already staged with an earlier capped parent");

                continue;
            }

            Schema::create($childStaging, function ($t) use ($childColumns) {
                $t->increments('stage_id');
                foreach ($childColumns as $col) {
                    $t->text($col)->nullable();
                }
            });

            $childCount = 0;
            $childQuery = fn () => $conn->table($childLegacy)
                ->where(function ($q) use ($fk, $keptIds) {
                    $q->whereIn($fk, $keptIds)->orWhereNull($fk);
                })
                ->select($childColumns);

            if ($childPk !== null) {
                $maxChild = (int) $conn->table($childLegacy)->max($childPk);
                for ($start = 0; $start <= $maxChild; $start += 2000) {
                    $rows = $childQuery()
                        ->whereBetween($childPk, [$start, min($start + 1999, $maxChild)])
                        ->get();
                    $childCount += $this->bulkInsert($childStaging, $rows);
                }
            } else {
                // PK-less child: one unpaginated filtered copy.
                $childCount = $this->bulkInsert($childStaging, $childQuery()->get());
            }

            $this->line("  ~ {$childLegacy}: kept rows referencing capped {$legacyTable} (".number_format($childCount).' rows)');
        }

        return $count;
    }

    /**
     * Live column list + primary key for a legacy table.
     *
     * @return array{0: string[], 1: ?string}|null
     */
    private function liveColumns(string $table): ?array
    {
        try {
            $cols = DB::connection('legacy')->select("SHOW COLUMNS FROM `{$table}`");
        } catch (\Throwable) {
            return null;
        }

        if ($cols === []) {
            return null;
        }

        $columns = array_map(fn ($c) => $c->Field, $cols);

        $pkRow = DB::connection('legacy')->selectOne(
            "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'"
        );

        return [$columns, $pkRow?->Column_name ?? null];
    }

    private function bulkInsert(string $table, $rows): int
    {
        $n = 0;
        foreach ($rows->chunk(500) as $part) {
            $payload = $part->map(fn ($r) => (array) $r)->all();
            DB::table($table)->insert($payload);
            $n += count($payload);
        }

        return $n;
    }
}
