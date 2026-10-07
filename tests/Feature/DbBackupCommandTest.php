<?php

namespace Tests\Feature;

use Tests\TestCase;

class DbBackupCommandTest extends TestCase
{
    /** A fresh temp backup dir (caller removes it after the test). */
    private function backupDir(): string
    {
        $dir = sys_get_temp_dir().'/db-backup-test-'.uniqid();

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->fail("Cannot create test backup dir [{$dir}].");
        }

        return $dir;
    }

    /** Remove a test backup dir and anything in it. */
    private function removeDir(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    /** Seed a fake dump file with a given age. */
    private function fakeDump(string $dir, string $name, int $ageDays): string
    {
        $file = $dir.'/'.$name;
        file_put_contents($file, "fake dump {$name}\n");
        touch($file, now()->subDays($ageDays)->getTimestamp());

        return $file;
    }

    public function test_prune_only_removes_expired_dumps_and_keeps_recent_ones(): void
    {
        $dir = $this->backupDir();
        $expired = $this->fakeDump($dir, '20260901_020000-app-stores_laravel.sql.gz', 30);
        $recent = $this->fakeDump($dir, '20261006_020000-app-stores_laravel.sql.gz', 1);

        try {
            $this->artisan('db:backup', [
                '--prune-only' => true,
                '--dir' => $dir,
                '--retention' => '14',
            ])->assertSuccessful();

            $this->assertFileDoesNotExist($expired);
            $this->assertFileExists($recent);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_a_failed_dump_leaves_retention_untouched_and_fails_the_run(): void
    {
        config(['backup.mysqldump' => sys_get_temp_dir().'/no-such-mysqld-'.uniqid()]);

        $dir = $this->backupDir();
        $existing = $this->fakeDump($dir, '20260901_020000-app-stores_laravel.sql.gz', 30);

        try {
            $this->artisan('db:backup', [
                '--dir' => $dir,
                '--retention' => '14',
                '--only' => 'app',
            ])->assertFailed();

            // Conservative pruning: a broken dump run must not erase the
            // last good backups, and it must not leave half-made files.
            $this->assertFileExists($existing);
            $this->assertCount(1, glob($dir.'/*.sql.gz') ?: []);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function test_an_unknown_only_value_is_rejected(): void
    {
        $this->artisan('db:backup', ['--only' => 'bogus'])->assertFailed();
    }

    public function test_the_nightly_schedule_is_registered(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('db:backup')
            ->assertSuccessful();
    }

    public function test_dumps_the_app_database_when_the_binary_is_available(): void
    {
        $binary = (string) config('backup.mysqldump');

        if ($binary === '' || ! file_exists($binary)) {
            $this->markTestSkipped('mysqldump binary not available on this host');
        }

        $dir = $this->backupDir();

        try {
            $this->artisan('db:backup', ['--dir' => $dir, '--only' => 'app'])->assertSuccessful();

            $dumps = glob($dir.'/*.sql.gz') ?: [];
            $this->assertCount(1, $dumps);
            $this->assertGreaterThan(0, filesize($dumps[0]));

            $sql = (string) gzdecode((string) file_get_contents($dumps[0]));
            $this->assertStringContainsString('-- Host:', $sql);
        } finally {
            $this->removeDir($dir);
        }
    }
}
