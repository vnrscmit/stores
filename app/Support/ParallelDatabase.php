<?php

namespace App\Support;

use App\Console\Commands\LegacyStageImport;

/**
 * Per-worker test database management for parallel PHPUnit runs (ParaTest).
 *
 * Model:
 *  - ONE template database (the plain test DB from phpunit.xml, e.g.
 *    stores_laravel_test) holds the populated, pipeline-built schema and the
 *    reduced legacy dataset.
 *  - Each ParaTest worker inherits a TEST_TOKEN env var (1..N) and gets its
 *    own clone: stores_laravel_test_1 .. _N, produced by dumping the template
 *    and re-importing it. Clones preserve FK constraints, so tests that
 *    assert constraint enforcement (Phase 4) behave identically to serial.
 *  - Serial runs (no TEST_TOKEN) keep using the template directly.
 *
 * This class is intentionally boot-free (raw PDO + console binaries) so it
 * can run from TestCase::setUp() BEFORE the Laravel application initialises.
 * The template rebuild is the one exception: it shells out to `php artisan`
 * with a DB_DATABASE override, so the pipeline lands in the template DB and
 * never in the dev database the current process is configured for.
 */
final class ParallelDatabase
{
    /** Tables that must exist for a database to count as a populated template/clone. */
    private const CORE_TABLES = ['users', 'issues', 'stock_ledger_goods'];

    /** Cap on pre-cloned workers, keeping prepare time sane on high-core boxes. */
    public const MAX_PRE_CLONES = 8;

    private static ?string $templateName = null;

    private static bool $prepared = false;

    /**
     * The template database name, resolved once per process from the first
     * available of: DB_DATABASE_TEST env, phpunit.xml, plain DB_DATABASE env.
     * Deliberately cached: prepare() mutates DB_DATABASE for workers, and the
     * template name must never shift mid-process (otherwise worker DB names
     * would chain, e.g. stores_laravel_test_1_1_1).
     */
    private static function templateName(): string
    {
        if (self::$templateName !== null) {
            return self::$templateName;
        }

        $name = getenv('DB_DATABASE_TEST');
        if ($name === false || $name === '') {
            $name = self::templateNameFromPhpUnitXml();
        }
        if ($name === null || $name === '') {
            $name = self::env('DB_DATABASE', 'stores_laravel_test');
        }

        return self::$templateName = $name;
    }

    /** Read DB_DATABASE_TEST (then DB_DATABASE) from phpunit.xml, boot-free. */
    private static function templateNameFromPhpUnitXml(): ?string
    {
        foreach ([getcwd().'/phpunit.xml', getcwd().'/phpunit.xml.dist'] as $file) {
            if (! is_file($file)) {
                continue;
            }
            $xml = file_get_contents($file);
            if ($xml === false) {
                continue;
            }
            foreach (['DB_DATABASE_TEST', 'DB_DATABASE'] as $key) {
                if (preg_match('/<env\s+name="'.$key.'"\s+value="([^"]+)"/', $xml, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    /**
     * Resolve the current process's test database and make sure it exists.
     * Called from TestCase::setUp() before the app boots; mutates the DB_DATABASE
     * env for worker processes so the framework connects to the worker clone.
     */
    public static function prepare(): void
    {
        if (self::$prepared) {
            return; // one-time per PHP process; keeps worker DB naming stable
        }
        self::$prepared = true;

        $base = self::templateName();
        $token = getenv('TEST_TOKEN');

        if ($token === false || $token === '' || (string) $token === '0') {
            // Serial run: use the template directly, creating it if the server
            // is reachable (missing schema surfaces later as a clear error).
            self::createDatabaseIfMissing($base);

            return;
        }

        $worker = $base.'_'.$token;
        self::overrideEnv('DB_DATABASE', $worker);

        if (self::isPopulated($worker)) {
            return; // warm clone from a previous run
        }

        if (! self::isPopulated($base)) {
            throw new \RuntimeException(
                "Test template database `{$base}` is missing or not populated.\n".
                "Build it once, then parallel runs reuse it:\n".
                '  php artisan tests:parallel-db'
            );
        }

        self::cloneDatabase($base, $worker);
    }

    /** Ensure the template exists and is populated; rebuild it if requested. */
    public static function ensureTemplate(bool $rebuild): void
    {
        $base = self::templateName();

        if ($rebuild || ! self::isPopulated($base)) {
            self::rebuildTemplate($base);

            // Worker clones snapshot the template — the old ones are stale,
            // so drop them and let preCloneWorkers re-derive fresh copies.
            self::cleanupWorkers();
        }
    }

    /** Pre-clone the template into worker DBs 1..$processes (skips warm clones). */
    public static function preCloneWorkers(int $processes): array
    {
        $base = self::templateName();

        if (! self::isPopulated($base)) {
            throw new \RuntimeException(
                "Test template database `{$base}` is missing or not populated — rebuild it first."
            );
        }

        $created = [];
        $processes = min(max($processes, 1), self::MAX_PRE_CLONES);

        for ($token = 1; $token <= $processes; $token++) {
            $worker = $base.'_'.$token;

            if (self::isPopulated($worker)) {
                continue;
            }

            self::cloneDatabase($base, $worker);
            $created[] = $worker;
        }

        return $created;
    }

    /** Drop every worker clone of the template (numeric `_N` suffixes only). */
    public static function cleanupWorkers(): array
    {
        $base = self::templateName();
        $pdo = self::pdo(null);

        $rows = $pdo->prepare(
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME LIKE CONCAT(?, '\\_%')
               AND SUBSTRING_INDEX(SCHEMA_NAME, '_', -1) REGEXP '^[0-9]+$'"
        );
        $rows->execute([$base]);

        $dropped = [];
        foreach ($rows->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            if ($name !== $base) {
                self::dropDatabase($name);
                $dropped[] = $name;
            }
        }

        return $dropped;
    }

    private static function rebuildTemplate(string $base): void
    {
        // Safety net: never rebuild a database that does not look like a test DB.
        if (! str_contains($base, 'test')) {
            throw new \RuntimeException(
                "Refusing to rebuild `{$base}` — the template database name must contain 'test'."
            );
        }

        self::dropDatabase($base);
        self::createDatabase($base);

        // Same reduced-dataset pipeline the suites run (see LegacyStageImport).
        $php = escapeshellarg(PHP_BINARY);
        $env = self::mysqlEnvWithOverride('DB_DATABASE', $base);

        foreach ([
            'migrate:fresh --force',
            'legacy:stage-import --limit='.LegacyStageImport::TEST_SUBSET_LIMIT,
            'legacy:migrate-data',
            'legacy:integrity-fix --strategy=placeholder',
            'legacy:add-constraints',
        ] as $step) {
            self::run("{$php} artisan {$step}", "Pipeline step `{$step}` failed", $env);
        }

        if (! self::isPopulated($base)) {
            throw new \RuntimeException("Template rebuild did not produce a populated `{$base}`.");
        }

        self::compactDatabase($base);
    }

    /**
     * Rewrite a database through dump -> drop -> reimport. The legacy staging
     * pipeline fragments tables (many small inserts/deletes), which slows every
     * subsequent test run; a dump/reimport leaves compact, contiguous pages.
     * Verified on this project: serial suite 226s -> ~60s after compacting.
     */
    private static function compactDatabase(string $db): void
    {
        $dumpFile = self::dumpDatabase($db);

        try {
            self::dropDatabase($db);
            self::createDatabase($db);
            self::importDump($db, $dumpFile);
        } finally {
            @unlink($dumpFile);
        }

        if (! self::isPopulated($db)) {
            throw new \RuntimeException("Compacted `{$db}` is missing core tables.");
        }
    }

    private static function cloneDatabase(string $source, string $target): void
    {
        self::dropDatabase($target);
        self::createDatabase($target);

        $dumpFile = self::dumpDatabase($source);

        try {
            self::importDump($target, $dumpFile);
        } finally {
            @unlink($dumpFile);
        }

        if (! self::isPopulated($target)) {
            throw new \RuntimeException("Clone `{$target}` is missing core tables after import.");
        }
    }

    /** Dump a whole database to a temporary SQL file and return its path. */
    private static function dumpDatabase(string $db): string
    {
        $dumpFile = sys_get_temp_dir().'/'.uniqid('stores_db_').'.sql';

        self::run(
            self::dbBin('DB_DUMP_BIN', 'mysqldump').' '.self::authArgs().
            ' --single-transaction --skip-lock-tables --no-tablespaces --default-character-set=utf8mb4'.
            ' '.escapeshellarg($db).' > '.escapeshellarg($dumpFile),
            "Dumping `{$db}` failed"
        );

        return $dumpFile;
    }

    /** Import a dump file into an existing (empty) database. */
    private static function importDump(string $target, string $dumpFile): void
    {
        self::run(
            self::dbBin('DB_IMPORT_BIN', 'mysql').' '.self::authArgs().
            ' --default-character-set=utf8mb4 '.escapeshellarg($target).
            ' < '.escapeshellarg($dumpFile),
            "Importing `{$target}` failed"
        );
    }

    private static function authArgs(): string
    {
        $args = '--host='.escapeshellarg((string) self::env('DB_HOST', '127.0.0.1'))
            .' --port='.escapeshellarg((string) self::env('DB_PORT', '3306'))
            .' --user='.escapeshellarg((string) self::env('DB_USERNAME', 'root'));

        $pass = (string) self::env('DB_PASSWORD', '');
        if ($pass !== '') {
            $args .= ' --password='.escapeshellarg($pass);
        }

        return $args;
    }

    /** Resolve a MySQL console binary: explicit env var, then known installs, then PATH. */
    private static function dbBin(string $envKey, string $name): string
    {
        $explicit = (string) self::env($envKey, '');
        if ($explicit !== '' && file_exists($explicit)) {
            return $explicit;
        }

        $candidates = [
            'C:/xampp/mysql/bin/'.$name.'.exe',
            'C:/wamp64/bin/mysql/mysql/bin/'.$name.'.exe',
            'C:/Program Files/MySQL/MySQL Server 8.0/bin/'.$name.'.exe',
            '/usr/bin/'.$name,
            '/usr/local/bin/'.$name,
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return $name; // last resort: rely on PATH
    }

    private static function run(string $cmd, string $what, ?array $env = null): void
    {
        $output = [];
        $code = 0;

        if ($env === null) {
            exec($cmd.' 2>&1', $output, $code);
        } else {
            // proc_merge... no: run with a temporary env overlay via cmd /c set on
            // Windows is fragile; instead export vars for this child process only.
            $old = [];
            foreach ($env as $k => $v) {
                $old[$k] = getenv($k);
                putenv($k.'='.$v);
                $_ENV[$k] = $v;
                $_SERVER[$k] = $v;
            }
            try {
                exec($cmd.' 2>&1', $output, $code);
            } finally {
                foreach ($old as $k => $v) {
                    if ($v === false) {
                        putenv($k);
                        unset($_ENV[$k], $_SERVER[$k]);
                    } else {
                        putenv($k.'='.$v);
                        $_ENV[$k] = $v;
                        $_SERVER[$k] = $v;
                    }
                }
            }
        }

        if ($code !== 0) {
            throw new \RuntimeException($what.":\n".implode("\n", array_slice($output, -15)));
        }
    }

    /** Env map for artisan child processes with one variable overridden. */
    private static function mysqlEnvWithOverride(string $key, string $value): array
    {
        $env = [];
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_LEGACY_DATABASE', 'APP_ENV'] as $k) {
            $v = self::env($k, '');
            if ($v !== '') {
                $env[$k] = $v;
            }
        }
        $env[$key] = $value;

        return $env;
    }

    private static function isPopulated(string $db): bool
    {
        if (! self::databaseExists($db)) {
            return false;
        }

        $stmt = self::pdo(null)->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?,?,?)'
        );
        $stmt->execute([$db, ...self::CORE_TABLES]);

        return (int) $stmt->fetchColumn() === count(self::CORE_TABLES);
    }

    private static function databaseExists(string $db): bool
    {
        $stmt = self::pdo(null)->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?'
        );
        $stmt->execute([$db]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function createDatabaseIfMissing(string $db): void
    {
        if (! self::databaseExists($db)) {
            self::createDatabase($db);
        }
    }

    private static function createDatabase(string $db): void
    {
        self::pdo(null)->exec(
            "CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    private static function dropDatabase(string $db): void
    {
        self::pdo(null)->exec("DROP DATABASE IF EXISTS `{$db}`");
    }

    private static function pdo(?string $db): \PDO
    {
        $dsn = 'mysql:host='.(string) self::env('DB_HOST', '127.0.0.1')
            .';port='.(string) self::env('DB_PORT', '3306')
            .($db !== null ? ';dbname='.$db : '');

        return new \PDO($dsn, (string) self::env('DB_USERNAME', 'root'), (string) self::env('DB_PASSWORD', ''), [
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }

    private static function overrideEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
}
