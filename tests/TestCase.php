<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Ensure the (possibly missing) test database exists before the app
     * boots — MariaDB will not create schemas implicitly. Only env() is
     * available at this point; the application is not booted yet.
     */
    protected function setUp(): void
    {
        $this->ensureTestDatabase();

        parent::setUp();
    }

    private function ensureTestDatabase(): void
    {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $user = env('DB_USERNAME', 'root');
        $pass = env('DB_PASSWORD', '');
        $db = env('DB_DATABASE_TEST', env('DB_DATABASE', 'stores_laravel_test'));

        try {
            $pdo = new \PDO("mysql:host={$host};port={$port}", $user, $pass, [
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            // Let the framework surface a clear connection error later.
        }
    }
}
