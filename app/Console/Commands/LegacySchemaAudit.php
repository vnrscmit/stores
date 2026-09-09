<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Parses the legacy mysqldump (structure section) into a machine-readable
 * JSON audit without requiring a database connection. Produces per-table:
 * columns (name, type, unsigned, nullable, default, auto_increment),
 * primary keys, keys, engine, and the auto-increment next value.
 *
 * The dump's layout is the standard phpMyAdmin export:
 *   CREATE TABLE `x` ( ... ) ENGINE=...;
 *   ALTER TABLE `x`
 *     ADD PRIMARY KEY (`id`),
 *     ADD KEY `k` (`col`);
 *   ALTER TABLE `x`
 *     MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123;
 */
class LegacySchemaAudit extends Command
{
    protected $signature = 'legacy:schema-audit
                            {--dump=storesd.sql : Path to the legacy mysqldump}
                            {--out=storage/app/legacy-schema.json : Output JSON path}';

    protected $description = 'Audit the legacy dump structure into JSON (no DB required)';

    public function handle(): int
    {
        $dumpPath = base_path($this->option('dump'));
        $outPath = base_path($this->option('out'));

        if (! is_file($dumpPath) || ! ($fh = fopen($dumpPath, 'r'))) {
            $this->error("Dump not found or unreadable: {$dumpPath}");

            return self::FAILURE;
        }

        $this->info("Parsing {$dumpPath} ...");

        $tables = [];
        $current = null;
        $inCreate = false;

        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");

            // CREATE TABLE starts a table block.
            if (preg_match('/^CREATE TABLE `([^`]+)`\s*\(/', $line, $m)) {
                $current = $m[1];
                $tables[$current] = [
                    'columns' => [],
                    'primary' => [],
                    'keys' => [],
                    'engine' => null,
                    'auto_increment' => null,
                ];
                $inCreate = true;

                continue;
            }

            if ($inCreate && $current !== null) {
                // Closing line: ) ENGINE=MyISAM ... ;
                if (preg_match('/^\)\s*ENGINE=(\w+)/i', $line, $m)) {
                    $tables[$current]['engine'] = strtolower($m[1]);
                    if (preg_match('/AUTO_INCREMENT=(\d+)/i', $line, $am)) {
                        $tables[$current]['auto_increment'] = (int) $am[1];
                    }
                    $inCreate = false;
                    $current = null;

                    continue;
                }

                // Column definition line: `name` type ...,
                if (preg_match('/^\s*`([^`]+)`\s+(.+?),?\s*$/', $line, $m)) {
                    $tables[$current]['columns'][$m[1]] = $this->parseColumn($m[2]);

                    continue;
                }

                // PRIMARY KEY (`a`,`b`)
                if (preg_match('/PRIMARY KEY\s*\((.+)\)/i', $line, $m)) {
                    $tables[$current]['primary'] = $this->splitKeyCols($m[1]);

                    continue;
                }

                // [UNIQUE] KEY `name` (`a`,`b`)
                if (preg_match('/(UNIQUE\s+)?KEY\s+`([^`]+)`\s*\((.+)\)/i', $line, $m)) {
                    $tables[$current]['keys'][] = [
                        'name' => $m[2],
                        'unique' => trim($m[1]) !== '',
                        'columns' => $this->splitKeyCols($m[3]),
                    ];

                    continue;
                }
            }
        }

        fclose($fh);

        // Second pass: ALTER TABLE sections (phpMyAdmin puts PK/keys and the
        // AUTO_INCREMENT MODIFY there).
        $fh = fopen($dumpPath, 'r');
        $alterTable = null;
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");

            if (preg_match('/^ALTER TABLE `([^`]+)`/', $line, $m)) {
                $alterTable = $m[1];

                continue;
            }

            if ($alterTable === null || ! isset($tables[$alterTable])) {
                continue;
            }

            // ADD PRIMARY KEY (`a`)
            if (preg_match('/ADD PRIMARY KEY\s*\((.+?)\)/i', $line, $m)) {
                $tables[$alterTable]['primary'] = $this->splitKeyCols($m[1]);

                continue;
            }

            // ADD KEY / ADD UNIQUE KEY `name` (`cols`)
            if (preg_match('/ADD (UNIQUE\s+)?KEY\s+`?([^`\s(]+)`?\s*\((.+?)\)/i', $line, $m)) {
                $tables[$alterTable]['keys'][] = [
                    'name' => $m[2],
                    'unique' => trim($m[1]) !== '',
                    'columns' => $this->splitKeyCols($m[3]),
                ];

                continue;
            }

            // MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123
            if (preg_match('/MODIFY `([^`]+)`\s+(.+)/i', $line, $m)) {
                $colName = $m[1];
                $def = $m[2];
                // Strip the trailing AUTO_INCREMENT=n clause and statement terminator.
                if (preg_match('/^(.*?),\s*AUTO_INCREMENT=(\d+)/i', $def, $am)) {
                    $def = $am[1];
                    $tables[$alterTable]['auto_increment'] = (int) $am[2];
                } else {
                    $def = preg_replace('/;\s*$/', '', $def);
                }
                if (isset($tables[$alterTable]['columns'][$colName])) {
                    $tables[$alterTable]['columns'][$colName] = $this->parseColumn($def);
                }

                continue;
            }
        }
        fclose($fh);

        $this->putJson($outPath, $tables);

        $nCols = array_sum(array_map(fn ($t) => count($t['columns']), $tables));
        $engines = array_count_values(array_map(fn ($t) => $t['engine'] ?? '?', $tables));
        $noPk = array_keys(array_filter($tables, fn ($t) => empty($t['primary'])));

        $this->info('Tables: '.count($tables)." | Columns: {$nCols}");
        foreach ($engines as $engine => $count) {
            $this->line("  engine {$engine}: {$count} tables");
        }
        if ($noPk !== []) {
            $this->warn('Tables WITHOUT primary key: '.implode(', ', $noPk));
        }
        $this->info("Written: {$outPath}");

        return self::SUCCESS;
    }

    private function parseColumn(string $def): array
    {
        $unsigned = (bool) preg_match('/unsigned/i', $def);
        $nullable = ! preg_match('/NOT NULL/i', $def);
        $autoinc = (bool) preg_match('/AUTO_INCREMENT/i', $def);

        $default = null;
        $hasDefault = false;
        if (preg_match('/DEFAULT\s+(NULL|\'[^\']*\'|[\-\d\.]+|CURRENT_TIMESTAMP)/i', $def, $m)) {
            $hasDefault = true;
            $default = $m[1];
            if (strtoupper($default) !== 'NULL' && str_starts_with($default, "'")) {
                $default = substr($default, 1, -1);
            }
        }

        $type = $def;
        $type = preg_replace('/\s+unsigned/i', '', $type);
        $type = preg_replace('/\s+NOT NULL.*$/i', '', $type);
        $type = preg_replace('/\s+DEFAULT.*$/i', '', $type);
        $type = preg_replace('/\s+AUTO_INCREMENT.*$/i', '', $type);
        $type = preg_replace('/\s+(COMMENT|CHARACTER SET|COLLATE)\s+.*$/i', '', $type);
        $type = trim($type, " \t,");

        return [
            'type' => $type,
            'unsigned' => $unsigned,
            'nullable' => $nullable,
            'default' => $hasDefault ? $default : null,
            'has_default' => $hasDefault,
            'auto_increment' => $autoinc,
        ];
    }

    private function splitKeyCols(string $raw): array
    {
        $cols = [];
        foreach (explode(',', $raw) as $part) {
            if (preg_match('/`([^`]+)`/', $part, $m)) {
                $cols[] = $m[1];
            }
        }

        return $cols;
    }

    private function putJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
