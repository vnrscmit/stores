<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Nightly off-site backup (operations roadmap): run mysqldump for the
 * port database and the legacy source database into the backup
 * directory, gzip each dump, print a SHA-256 per file, then prune
 * dumps beyond the retention window.
 *
 * Scheduled daily at 02:00 in routes/console.php; the Windows Task
 * Scheduler heartbeat (docs/DEPLOYMENT.md) drives it through
 * `schedule:run`. Point BACKUP_DIR at a second disk or network share —
 * the default (storage/backups) is same-disk, i.e. NOT off-site.
 *
 * The dump is written through mysqldump's `--result-file` and then
 * gzipped with PHP's zlib streams in chunks, so no shell pipes are
 * needed (Task Scheduler runs artisan without a shell). The DB
 * password is passed as `-p<password>` on the dump command line —
 * visible in a process listing for the dump's lifetime, acceptable on
 * the single-operator XAMPP box this system targets; it is never
 * written to the log or the command output.
 *
 * Retention is deliberately applied only after every requested dump
 * succeeded: a broken mysqldump must never erase the last good
 * backups. Use --prune-only to apply the retention window without
 * dumping (e.g. after tightening BACKUP_RETENTION_DAYS).
 */
class DbBackup extends Command
{
    /** Logical name -> connection config key (config/database.php). */
    private const TARGETS = [
        'app' => 'database.connections.mysql',
        'legacy' => 'database.connections.legacy',
    ];

    /** Both dumps share one timestamp so paired files sort together. */
    private string $stamp;

    protected $signature = 'db:backup
                            {--dir= : Destination directory (default: config backup.destination)}
                            {--retention= : Days of dumps to keep (default: config backup.retention_days)}
                            {--only= : Restrict to one database: "app" or "legacy"}
                            {--prune-only : Skip dumping; apply the retention window to existing dumps}';

    protected $description = 'Dump both databases with mysqldump and prune dumps beyond the retention window';

    public function handle(): int
    {
        $dir = (string) ($this->option('dir') ?: config('backup.destination'));
        $retention = (int) ($this->option('retention') ?: config('backup.retention_days'));

        if ($retention < 1) {
            $this->error('The retention window must be at least 1 day.');

            return self::FAILURE;
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Cannot create the backup directory [{$dir}].");

            return self::FAILURE;
        }

        $targets = $this->targets();
        if ($targets === null) {
            return self::FAILURE;
        }

        $failures = 0;

        if (! $this->pruneOnly()) {
            $this->stamp = now()->format('Ymd_His');
            $failures = $this->dumpTargets($dir, $targets);
        }

        if ($failures > 0) {
            // Conservative: keep the old dumps, say so, fail the run —
            // the scheduler/log is where the next morning's review
            // starts.
            $this->warn('Retention window left untouched because a dump failed.');
            Log::error('db:backup: dump(s) failed, retention untouched.', [
                'failed' => $failures,
            ]);

            return self::FAILURE;
        }

        $pruned = $this->prune($dir, $retention);

        Log::info('db:backup: completed.', [
            'prune_only' => $this->pruneOnly(),
            'retention_days' => $retention,
            'pruned' => $pruned,
            'destination' => $dir,
        ]);

        return self::SUCCESS;
    }

    /**
     * Connection configs per target, honouring --only; null on a bad
     * --only value (already reported).
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function targets(): ?array
    {
        $targets = self::TARGETS;

        if (($only = $this->option('only')) !== null) {
            if (! isset($targets[(string) $only])) {
                $this->error('--only must be "app" or "legacy".');

                return null;
            }

            $targets = [(string) $only => $targets[(string) $only]];
        }

        $configs = [];

        foreach ($targets as $name => $key) {
            $configs[$name] = (array) config($key);
        }

        return $configs;
    }

    private function pruneOnly(): bool
    {
        return (bool) $this->option('prune-only');
    }

    /**
     * Dump each target; returns the number of failures. All targets are
     * attempted even after one fails, so a run surfaces every problem.
     *
     * @param  array<string, array<string, mixed>>  $targets
     */
    private function dumpTargets(string $dir, array $targets): int
    {
        $binary = (string) config('backup.mysqldump');
        $timeout = (int) config('backup.timeout', 900);
        $failures = 0;

        foreach ($targets as $name => $conn) {
            $database = (string) ($conn['database'] ?? '');
            $file = $dir.'/'.$this->stamp.'-'.$name.'-'.$this->safeName($database).'.sql.gz';

            if ($database === '' || ! $this->dump($binary, $timeout, $conn, $database, $file)) {
                $failures++;
            }
        }

        return $failures;
    }

    /**
     * One mysqldump -> gzip cycle. Reports and returns false on failure;
     * partial artifacts are removed. The empty-password case omits the
     * -p flag entirely (mysqldump would prompt on `-p ""`).
     *
     * Stored routines are included (--routines) — but XAMPP's MariaDB
     * 10.4 throws "Unknown error (1105)" on the SHOW FUNCTION STATUS
     * that --routines performs, so a failing dump retries once without
     * the flag and reports the downgrade rather than failing every
     * night on a server quirk. The stored procedures/functions are then
     * missing from that dump — the log line is the audit trail.
     *
     * @param  array<string, mixed>  $conn
     */
    private function dump(string $binary, int $timeout, array $conn, string $database, string $file): bool
    {
        $raw = substr($file, 0, -3);

        $base = [
            '--host='.(string) ($conn['host'] ?? '127.0.0.1'),
            '--port='.(string) ($conn['port'] ?? '3306'),
            '--user='.(string) ($conn['username'] ?? ''),
            '--single-transaction',
            '--triggers',
            '--result-file='.$raw,
            $database,
        ];

        if (($password = (string) ($conn['password'] ?? '')) !== '') {
            $base[] = '-p'.$password;
        }

        $withRoutines = true;

        while (true) {
            $args = array_merge([$binary], $base);

            if ($withRoutines) {
                array_splice($args, 1, 0, ['--routines']);
            }

            $process = new Process($args, null, null, null, $timeout);

            try {
                $process->run();
            } catch (\Throwable $e) {
                $this->error("[{$database}] dump failed to start: ".$e->getMessage());
                Log::error('db:backup: dump failed to start.', ['database' => $database]);

                return false;
            }

            if ($process->isSuccessful() && is_file($raw)) {
                break;
            }

            @unlink($raw);

            if (! $withRoutines) {
                $this->error("[{$database}] mysqldump failed: ".trim($process->getErrorOutput()));
                Log::error('db:backup: mysqldump failed.', ['database' => $database]);

                return false;
            }

            // Retry without --routines (see the docblock).
            $withRoutines = false;
        }

        if (! $this->gzip($raw, $file)) {
            $this->error("[{$database}] gzip compression failed.");
            Log::error('db:backup: gzip failed.', ['database' => $database]);
            @unlink($raw);

            return false;
        }

        @unlink($raw);

        if (! $withRoutines) {
            $this->warn("[{$database}] dumped without stored routines (--routines unsupported on this server).");
            Log::warning('db:backup: dump lacks stored routines.', ['database' => $database]);
        }

        $this->info(sprintf(
            '[%s] %s (%s) — sha256 %s',
            $database,
            basename($file),
            $this->humanBytes((int) (filesize($file) ?: 0)),
            (string) (hash_file('sha256', $file) ?: 'unavailable'),
        ));

        return true;
    }

    /**
     * Chunked gzip so a 75 MB dump never sits in memory as one string.
     */
    private function gzip(string $source, string $target): bool
    {
        $in = @fopen($source, 'rb');

        if ($in === false) {
            return false;
        }

        $out = @gzopen($target, 'wb9');

        if ($out === false) {
            fclose($in);

            return false;
        }

        $ok = true;

        while (! feof($in)) {
            $chunk = fread($in, 262144);

            if ($chunk === false || $chunk === '' || gzwrite($out, $chunk) === false) {
                $ok = false;

                break;
            }
        }

        fclose($in);
        gzclose($out);

        if ($ok && ! is_file($target)) {
            $ok = false;
        }

        return $ok;
    }

    /**
     * Delete dumps older than the retention window; returns how many
     * were removed. Only *.sql.gz files in the (dedicated) backup
     * directory are considered.
     */
    private function prune(string $dir, int $retention): int
    {
        $cutoff = now()->subDays($retention)->getTimestamp();
        $pruned = 0;

        foreach (glob($dir.'/*.sql.gz') ?: [] as $file) {
            $mtime = filemtime($file);

            if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
                $pruned++;
            }
        }

        $this->info($pruned > 0
            ? "Pruned {$pruned} dump(s) older than {$retention} day(s)."
            : "Nothing to prune (retention {$retention} day(s)).");

        return $pruned;
    }

    private function safeName(string $database): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $database) ?: 'db';
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }

        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return $bytes.' B';
    }
}
