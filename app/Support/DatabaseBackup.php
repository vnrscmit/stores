<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Streaming SQL dump generator — the port of utility/backup.php (the
 * admin navbar "Backup" popup) and utility/backup1.php (the superseded
 * 51-table data-only variant).
 *
 * Legacy behaviour preserved verbatim:
 *  - the FULL mode dumps EVERY table: `SHOW CREATE TABLE` followed by
 *    one INSERT per row, no DROP statements (legacy commented them out)
 *    and no FK toggles — a dump restores into an EMPTY database, exactly
 *    as the legacy file was meant to be used;
 *  - the filename pattern `Backup_{database}_{d-m-Y}.sql` (legacy
 *    hardcoded the `stores` name — the port uses the live database name,
 *    which IS `stores` in production);
 *  - the BUSINESS mode dumps the fixed 51-table legacy list, data only,
 *    INSERTs without column lists (full column order).
 *
 * Deliberate deviations (documented):
 *  - the dump is STREAMED (generator → StreamedResponse) instead of
 *    buffered in one in-memory string; memory is bounded per table;
 *  - rows are read table-at-a-time in deterministic PK order (legacy
 *    relied on unordered heap reads), so repeated dumps diff cleanly;
 *  - SQL NULL renders as NULL (legacy wrote empty strings — a bug that
 *    made restored rows lose their NULL-ness);
 *  - strings escape only single quotes (legacy's addslashes also
 *    backslash-escaped, which corrupts `\\` literals on import);
 *  - a short comment header identifies the port and its parameters;
 *    the legacy file started straight at the first CREATE TABLE.
 */
class DatabaseBackup
{
    /**
     * The 51 tables of utility/backup1.php, in its dump order. The legacy
     * script ran on the legacy schema, so each entry maps its legacy name
     * to the port table holding the same business data (null = the port
     * has no dedicated table — merged into users/issues/issue_items or
     * absent); the business dump records those as comments so the parity
     * gap stays visible in the file itself.
     */
    public const BUSINESS_TABLES = [
        'tbl_bin' => 'bins',
        'tbl_captive' => 'captives',
        'tbl_captive_sloc' => 'captive_slocs',
        'tbl_captivesub' => 'captive_items',
        'tbl_ci' => 'cycle_counts',
        'tbl_ciupdation' => 'cycle_count_updates',
        'tbl_classification' => 'classifications',
        'tbl_discard' => 'discards',
        'tbl_discard_sloc' => 'discard_slocs',
        'tbl_discard_sub' => 'discard_items',
        'tbl_dtog' => 'dtogs',
        'tbl_dtog_sub' => 'dtog_items',
        'tbl_ecaptive' => 'external_captives',
        'tbl_eindents' => 'e_indents',
        'tbl_excess' => 'excesses',
        'tbl_excess_sub' => 'excess_items',
        'tbl_gate' => 'gate_passes',
        'tbl_gtod' => 'gtods',
        'tbl_gtod_sub' => 'gtod_items',
        'tbl_icaptive' => 'internal_captives',
        'tbl_ieindent' => 'issue_stock_headers',
        'tbl_ieindent_sub' => 'issue_items',
        'tbl_iitr' => 'item_transfers',
        'tbl_iitr_sub' => 'item_transfer_items',
        'tbl_ireturn' => 'internal_returns',
        'tbl_issuestock' => 'issues',
        'tbl_opr' => null,
        'tbl_order' => null,
        'tbl_parameters' => 'company_settings',
        'tbl_party_ldg' => 'party_ledgers',
        'tbl_partymaser' => 'parties',
        'tbl_pindents' => 'pindents',
        'tbl_report' => 'report_definitions',
        'tbl_roles' => null,
        'tbl_sloc' => 'slocs',
        'tbl_sloc_sub' => 'sloc_items',
        'tbl_stldg_damage' => 'stock_ledger_damages',
        'tbl_stldg_good' => 'stock_ledger_goods',
        'tbl_stock' => 'stock_headers',
        'tbl_stores' => 'items',
        'tbl_subbin' => 'sub_bins',
        'tbl_user' => 'users',
        'tbl_viewer' => null,
        'tbl_warehouse' => 'warehouses',
        'tblarr_sloc' => 'arrival_slocs',
        'tblarrival' => 'arrivals',
        'tblarrival_sub' => 'arrival_items',
        'tblissue' => null,
        'tblissue_sloc' => 'issue_slocs',
        'tblissue_sub' => null,
        'tblyears' => 'financial_years',
    ];

    /**
     * Consume a dump generator, echoing its chunks (the StreamedResponse
     * callback). flush() pushes completed chunks out under FPM; the
     * response layer owns any output buffering.
     *
     * @param  \Generator<int, string>  $dump
     */
    public static function stream(\Generator $dump): void
    {
        foreach ($dump as $chunk) {
            echo $chunk;

            if (connection_aborted()) {
                break;
            }

            flush();
        }
    }

    /**
     * The download filename (legacy pattern, live database name).
     */
    public static function filename(): string
    {
        return 'Backup_'.DB::connection()->getDatabaseName().'_'.now()->format('d-m-Y').'.sql';
    }

    /**
     * Full-database dump (utility/backup.php): schema + data of every
     * table, streamed as chunks of SQL text.
     *
     * @return \Generator<int, string>
     */
    public static function fullDump(): \Generator
    {
        yield from self::header('full');

        $tables = DB::select('SHOW TABLES');

        foreach ($tables as $table) {
            $name = (string) array_values((array) $table)[0];

            yield "--\n-- Table structure for table `{$name}`\n--\n\n";

            $create = DB::selectOne("SHOW CREATE TABLE `{$name}`");
            $createSql = array_values((array) $create)[1] ?? null;

            yield (($createSql === null)
                ? "-- (schema unavailable for `{$name}`)\n"
                : $createSql.";\n")."\n";

            yield from self::insertsFor($name);
        }
    }

    /**
     * Business-tables dump (utility/backup1.php): the fixed 51-table
     * legacy list mapped onto the port's tables, data only, INSERTs
     * without column lists — rows are written in full column order
     * exactly like the legacy file. Legacy names without a dedicated
     * port table are recorded as comments (merged or absent).
     *
     * @return \Generator<int, string>
     */
    public static function businessDump(): \Generator
    {
        yield from self::header('business');

        foreach (self::BUSINESS_TABLES as $legacy => $port) {
            if ($port === null) {
                yield "-- (legacy table {$legacy} has no dedicated port table — merged or absent)\n";

                continue;
            }

            if (! Schema::hasTable($port)) {
                yield "-- (legacy table not present in this port: {$legacy} -> {$port})\n";

                continue;
            }

            yield from self::insertsFor($port, false);
        }
    }

    /**
     * The file header (a deliberate addition — see the class docblock).
     *
     * @return \Generator<int, string>
     */
    private static function header(string $mode): \Generator
    {
        yield "-- Stores Management System — database backup (Laravel port of\n";
        yield '-- utility/backup.php'.($mode === 'business' ? ' / backup1.php (business tables, data only)' : '').")\n";
        yield '-- Database: '.DB::connection()->getDatabaseName()."\n";
        yield '-- Generated: '.now()->format('d-m-Y H:i:s')."\n";
        yield "-- Mode: {$mode}\n";
        yield "-- Restore into an EMPTY database (no DROP statements, verbatim\n";
        yield "-- legacy dump semantics).\n\n";
    }

    /**
     * One INSERT per row for a table (legacy escaping semantics with the
     * documented deviations). $named selects the column-list form used by
     * the full dump; the business dump keeps legacy's bare form.
     *
     * @return \Generator<int, string>
     */
    private static function insertsFor(string $table, bool $named = true): \Generator
    {
        $columns = DB::select("SHOW COLUMNS FROM `{$table}`");
        $colNames = array_map(fn ($c) => (string) $c->Field, $columns);

        // Deterministic read order: PK where there is one (documented
        // deviation — legacy read in heap order).
        $pk = null;
        foreach ($columns as $column) {
            if ($column->Key === 'PRI') {
                $pk = (string) $column->Field;

                break;
            }
        }

        $rows = DB::cursor(
            'SELECT * FROM `'.$table.'`'.($pk !== null ? ' ORDER BY `'.$pk.'` ASC' : '')
        );

        $prefix = $named
            ? 'INSERT INTO `'.$table.'` ('.implode(', ', array_map(fn ($c) => '`'.$c.'`', $colNames)).') VALUES ('
            : 'INSERT INTO '.$table.' VALUES (';

        foreach ($rows as $row) {
            yield $prefix.implode(', ', array_map([self::class, 'sqlLiteral'], array_values((array) $row))).");\n";
        }

        yield "\n";
    }

    /**
     * One SQL literal (see the class docblock for the deviations).
     */
    private static function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // DateTime/Carbon values from the DB layer.
        if (is_object($value) && method_exists($value, 'format')) {
            return "'".str_replace("'", "''", $value->format('Y-m-d H:i:s'))."'";
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }
}
