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
    protected $signature = 'legacy:stage-import
                            {--tables= : Comma-separated subset of legacy tables}';

    protected $description = 'Copy legacy tables verbatim into legacy_* staging tables';

    public function handle(): int
    {
        $legacyDb = config('database.connections.legacy.database');
        $map = config('legacy-map-tables');

        $tables = $this->option('tables')
            ? explode(',', $this->option('tables'))
            : array_keys($map);

        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

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

            Schema::dropIfExists($staging);
            Schema::create($staging, function ($t) use ($columns) {
                $t->increments('stage_id');
                foreach ($columns as $col) {
                    $t->text($col)->nullable();
                }
            });

            $count = 0;
            if ($pk !== null) {
                $max = (int) DB::connection('legacy')->table($legacyTable)->max($pk);
                for ($start = 0; $start <= $max; $start += 2000) {
                    $rows = DB::connection('legacy')->table($legacyTable)
                        ->whereBetween($pk, [$start, $start + 2000])
                        ->select($columns)
                        ->get();
                    $count += $this->bulkInsert($staging, $rows);
                }
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
