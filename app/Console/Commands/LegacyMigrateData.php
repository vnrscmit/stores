<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2 of the pipeline: transform legacy_* staging rows into the final
 * normalized tables.
 *
 * Rules (locked to the prior migration audit):
 *   - '0000-00-00' pseudo-dates -> NULL (applied to every final date column).
 *   - FK columns: '0' / '' sentinels -> NULL (constraint-eligible columns).
 *   - Auth consolidation: tbl_user + tbl_opr + tbl_roles + tbl_viewer ->
 *     users with bcrypt passwords and legacy traceability columns.
 *   - document_counters seeded from per-year MAX(code) so numbering continues.
 *
 * Types are introspected from the FINAL tables (live), staging columns from
 * the staging tables (live) — the dump audit is not trusted for data.
 */
class LegacyMigrateData extends Command
{
    protected $signature = 'legacy:migrate-data
                            {--tables= : Comma-separated subset of legacy tables}
                            {--chunk=1000}';

    protected $description = 'Transform staging data into the final Laravel tables';

    public function handle(): int
    {
        $map = config('legacy-map-tables');
        $relations = config('legacy-map-relations');
        $chunk = (int) $this->option('chunk');

        DB::statement("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

        $fkColumns = [];
        foreach ($relations['eligible'] as $rel) {
            $fkColumns[$rel['child']][$rel['column']] = true;
        }

        $this->consolidateAuth();

        $tables = $this->option('tables')
            ? explode(',', $this->option('tables'))
            : array_keys($map);

        foreach ($tables as $legacyTable) {
            $conf = $map[$legacyTable] ?? null;
            if ($conf === null || ! empty($conf['ignore']) || ! empty($conf['consolidate'])) {
                continue;
            }

            $staging = 'legacy_'.$legacyTable;
            $final = $conf['new_name'];
            if (! Schema::hasTable($staging) || ! Schema::hasTable($final)) {
                $this->warn("  - {$legacyTable}: staging or final table missing, skipped");

                continue;
            }

            // Live schemas.
            $stagingCols = Schema::getColumnListing($staging);
            $finalCols = array_flip(Schema::getColumnListing($final));

            $columns = [];
            foreach ($stagingCols as $col) {
                if ($col === 'stage_id') {
                    continue;
                }
                if (isset($finalCols[$col])) {
                    $columns[] = $col;
                }
            }

            // Final column types (Laravel mapped names) for casting + date rules.
            $types = [];
            $dateCols = [];
            foreach ($columns as $col) {
                $type = Schema::getColumnType($final, $col);
                $types[$col] = $type;
                if (in_array($type, ['date', 'datetime'], true)) {
                    $dateCols[] = $col;
                }
            }

            $sentinels = array_intersect_key($fkColumns[$final] ?? [], array_flip($columns));

            // Primary key of the final table — live legacy data can contain
            // duplicate PKs (MyISAM without enforced keys); first wins.
            $pkCols = collect(Schema::getIndexes($final))
                ->first(fn ($ix) => $ix['name'] === 'primary')['columns'] ?? [];
            $pkCols = array_values(array_intersect($pkCols, $columns));

            Schema::disableForeignKeyConstraints();
            DB::table($final)->truncate();

            $count = 0;
            $scrubbedDates = 0;
            $scrubbedSentinels = 0;
            $dupes = 0;
            $seenPk = [];

            DB::table($staging)
                ->orderBy('stage_id')
                ->chunk($chunk, function ($rows) use ($final, $columns, $types, $dateCols, $sentinels, $pkCols, &$count, &$scrubbedDates, &$scrubbedSentinels, &$dupes, &$seenPk) {
                    $payload = [];
                    foreach ($rows as $row) {
                        $rec = [];
                        foreach ($columns as $col) {
                            $val = $row->{$col};

                            if ($val === null) {
                                $rec[$col] = null;

                                continue;
                            }

                            // Zero-date scrub.
                            if (in_array($col, $dateCols, true) && preg_match('/^0{4}-0{2}-0{2}/', (string) $val)) {
                                $rec[$col] = null;
                                $scrubbedDates++;

                                continue;
                            }

                            // FK sentinel scrub.
                            if (isset($sentinels[$col]) && ($val === '0' || $val === '' || $val === 0)) {
                                $rec[$col] = null;
                                $scrubbedSentinels++;

                                continue;
                            }

                            $rec[$col] = $this->castForInsert($types[$col], $val);
                        }

                        // Duplicate PK guard: keep the first occurrence.
                        if ($pkCols !== []) {
                            $key = implode('|', array_map(fn ($c) => (string) $rec[$c], $pkCols));
                            if (isset($seenPk[$key])) {
                                $dupes++;

                                continue;
                            }
                            $seenPk[$key] = true;
                        }

                        $payload[] = $rec;
                        $count++;
                    }

                    foreach (array_chunk($payload, 300) as $part) {
                        DB::table($final)->insert($part);
                    }
                });

            unset($seenPk); // free memory on big tables
            Schema::enableForeignKeyConstraints();

            $extra = trim(($scrubbedDates ? ' ['.number_format($scrubbedDates).' zero-dates->NULL]' : '')
                .($scrubbedSentinels ? ' ['.number_format($scrubbedSentinels).' sentinels->NULL]' : '')
                .($dupes > 0 ? ' ['.number_format($dupes).' duplicate PK rows skipped]' : ''));
            $this->line("  + {$legacyTable} -> {$final} (".number_format($count)." rows){$extra}");
        }

        $this->seedDocumentCounters();

        return self::SUCCESS;
    }

    private function consolidateAuth(): void
    {
        DB::table('users')->truncate();

        $now = now();
        $n = 0;
        $skipped = 0;

        /*
         * Legacy auth (validatelogin.php) authenticates against tbl_user
         * FIRST; the other three tables are profile sources joined by login
         * name. On cross-table duplicates the tbl_user row is therefore
         * authoritative, then tbl_opr, tbl_roles, tbl_viewer (in order).
         */

        $sources = [
            'tbl_user' => [
                'login' => 'loginid', 'password' => 'password', 'role' => null,
                'name' => 'loginid', 'email' => 'email', 'status' => 'status',
                'code' => 'uid', 'question' => 'question', 'answer' => 'answer',
            ],
            'tbl_opr' => [
                'login' => 'login', 'password' => 'pass', 'role' => 'operator',
                'name' => 'name', 'email' => 'email', 'status' => 'status',
                'code' => 'code', 'question' => null, 'answer' => null,
            ],
            'tbl_roles' => [
                'login' => 'login', 'password' => 'pass', 'role' => 'eindent',
                'name' => 'name', 'email' => 'email', 'status' => 'status',
                'code' => 'code', 'question' => null, 'answer' => null,
            ],
            'tbl_viewer' => [
                'login' => 'login', 'password' => 'pass', 'role' => 'viewer',
                'name' => 'name', 'email' => 'email', 'status' => 'status',
                'code' => 'vcode', 'question' => null, 'answer' => null,
            ],
        ];

        foreach ($sources as $legacyTable => $f) {
            $staging = 'legacy_'.$legacyTable;
            if (! Schema::hasTable($staging)) {
                $this->warn("  - {$staging} missing, auth source skipped");

                continue;
            }

            $cols = array_flip(Schema::getColumnListing($staging));
            $has = fn ($c) => isset($cols[$c]);

            DB::table($staging)->orderBy('stage_id')->chunk(500, function ($rows) use ($legacyTable, $f, $now, $has, &$n, &$skipped) {
                $payload = [];
                foreach ($rows as $row) {
                    $login = trim((string) $row->{$f['login']});
                    $pass = (string) $row->{$f['password']};

                    if ($login === '' || $pass === '') {
                        continue;
                    }

                    // Cross-table duplicate login: first (highest-precedence) wins.
                    if (DB::table('users')->where('login', $login)->exists()) {
                        $skipped++;

                        continue;
                    }

                    $role = $f['role'] ?? strtolower(trim((string) $row->role));
                    if (! in_array($role, ['admin', 'operator', 'eindent', 'viewer'], true)) {
                        $role = 'viewer';
                    }

                    $status = strtolower(trim((string) $row->{$f['status']})) === 'active' ? 'Active' : 'Suspend';

                    $payload[] = [
                        'login' => $login,
                        // Legacy plaintext hashed now; verify-and-rehash handles
                        // any already-hashed values at login time.
                        'password' => str_starts_with($pass, '$2y$') ? $pass : bcrypt($pass),
                        'role' => $role,
                        'name' => ($f['name'] !== null && $has($f['name'])) ? trim((string) $row->{$f['name']}) : null,
                        'email' => ($f['email'] !== null && $has($f['email'])) ? trim((string) $row->{$f['email']}) : null,
                        'status' => $status,
                        'code' => ($f['code'] !== null && $has($f['code'])) ? (string) $row->{$f['code']} : null,
                        'legacy_id' => $row->id ?? $row->vid ?? null,
                        'legacy_source' => $legacyTable,
                        'question' => ($f['question'] !== null && $has($f['question'])) ? trim((string) $row->question) : null,
                        'answer' => ($f['answer'] !== null && $has($f['answer'])) ? trim((string) $row->answer) : null,
                        'uid' => $has('uid') ? ($row->uid ?: null) : null,
                        'scode' => $has('scode') ? $row->scode : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $n++;
                }
                foreach (array_chunk($payload, 200) as $part) {
                    DB::table('users')->insert($part);
                }
            });
        }

        $extra = $skipped > 0 ? ', '.number_format($skipped).' duplicate logins skipped (legacy tbl_user precedence)' : '';
        $this->info('  + auth consolidation -> users ('.number_format($n).' accounts'.$extra.')');
    }

    /**
     * Cast a TEXT-typed staging value to the final column's mapped type.
     */
    private function castForInsert(string $type, ?string $val): mixed
    {
        if ($val === null || $val === '') {
            return null;
        }

        switch ($type) {
            case 'integer':
            case 'bigint':
            case 'smallint':
            case 'tinyint':
                return (int) $val;
            case 'decimal':
            case 'float':
            case 'double':
                return (float) $val;
            default:
                return $val; // string / text / date as-is
        }
    }

    /**
     * Seed document_counters from per-year MAX(code) in the final tables.
     */
    private function seedDocumentCounters(): void
    {
        DB::table('document_counters')->truncate();

        $sources = [
            ['eindent', 'e_indents', 'yearcode', 'code', null],
            ['issue.eindent', 'issues', 'yearcode', 'iss_code', "issue_type = 'eindent'"],
            ['issue.pindent', 'issues', 'yearcode', 'iss_code', "issue_type = 'pindent'"],
            ['issue.stocktr', 'issues', 'yearcode', 'iss_code', "issue_type = 'stocktr'"],
            ['arrival.vendor', 'arrivals', 'yearcode', 'arr_code', "arrival_type = 'vendor'"],
            ['arrival.stocktr', 'arrivals', 'yearcode', 'arr_code', "arrival_type = 'stocktransfer'"],
            ['captive.vendor', 'captives', 'yearcode', 'cc_code', null],
            ['discard', 'discards', 'yearcode', 'dd_code', null],
            ['excess', 'excesses', 'yearcode', 'escode', null],
            ['sloc', 'slocs', 'yearcode', 'scode', null],
            ['iitr', 'item_transfers', 'yearcode', 'iitr_code', null],
            ['dtog', 'dtogs', 'yearcode', 'dcode', null],
            ['gtod', 'gtods', 'yearcode', 'gcode', null],
        ];

        $n = 0;
        foreach ($sources as [$type, $table, $yearCol, $codeCol, $whereRaw]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $q = DB::table($table)
                ->selectRaw("`{$yearCol}` as yc, MAX(`{$codeCol}`) as mx")
                ->whereNotNull($yearCol)
                ->where($yearCol, '!=', '')
                ->groupBy($yearCol);

            if ($whereRaw) {
                $q->whereRaw($whereRaw);
            }

            foreach ($q->get() as $r) {
                if ($r->yc === null || $r->mx === null) {
                    continue;
                }
                DB::table('document_counters')->updateOrInsert(
                    ['doc_type' => $type, 'yearcode' => $r->yc],
                    ['current_value' => (int) $r->mx, 'created_at' => now(), 'updated_at' => now()]
                );
                $n++;
            }
        }

        $this->info('  + document_counters seeded ('.number_format($n).' type/year rows)');
    }
}
