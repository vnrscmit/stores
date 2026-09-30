<?php

/**
 * CI legacy-fixture restore — the single code path shared by the GitHub
 * Actions workflow (tests.yml) and the local rehearsal (FIXTURE_KEY file).
 *
 * Ensures the legacy `stores` database is present and populated, restoring
 * it from the encrypted fixture (database/fixtures/legacy-stores.sql.gz.enc,
 * aes-256-cbc/base64 of the canonical storesd.sql snapshot) when it is
 * missing or empty, and ensures the empty app-side test database exists —
 * the prerequisites the hermetic suite builds on (the first gated Feature
 * class runs the stage-import pipeline against the restored legacy DB and
 * fills the test DB; every other class reuses the result).
 *
 * Safety contract (learned the hard way): the fixture is fully decrypted,
 * decompressed and validated BEFORE any database statement runs, and a
 * populated `stores` database is never dropped — the restore only fires
 * when the database is absent or empty. Re-running is always safe.
 *
 * PDO-only by design: no mysql/mysqldump CLI is guaranteed on the runner
 * image, and the Windows XAMPP binaries have locale quirks with their own
 * dumps.
 *
 * Environment: FIXTURE_KEY (required), DB_HOST/DB_PORT/DB_USERNAME/
 * DB_PASSWORD (defaults 127.0.0.1 / 3306 / root / empty).
 *
 * Local rehearsal:
 *   FIXTURE_KEY=$(cat database/fixtures/FIXTURE_KEY.txt) \
 *     php tests/support/ci_fixture_restore.php
 */
$key = trim((string) getenv('FIXTURE_KEY'));

// Tolerate the classic manual-paste accidents (trailing newline, spaces,
// CRLF) — the key is 48-char hex, so trimming can never mangle a real key.
if ($key === '' || ! preg_match('/^[0-9a-fA-F]{48}$/', $key)) {
    fwrite(STDERR, 'FIXTURE_KEY env is missing or malformed (expected 48 hex chars, got '.strlen($key)."). Check the GitHub secret for stray whitespace/newlines.\n");
    exit(1);
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (string) (getenv('DB_PORT') ?: '3306');
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30],
);

$enc = __DIR__.'/../../database/fixtures/legacy-stores.sql.gz.enc';
if (! is_file($enc)) {
    fwrite(STDERR, "Fixture not found: {$enc}\n");
    exit(1);
}

// ---- Phase 1: decrypt + decompress + validate. No DB is touched here. ----

$payload = base64_decode((string) file_get_contents($enc), true);
if ($payload === false || strlen($payload) < 17) {
    fwrite(STDERR, "Fixture is not valid base64/payload.\n");
    exit(1);
}

// Format: base64( 16-byte random IV || aes-256-cbc(gzip(dump)) ).
$gz = openssl_decrypt(substr($payload, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($payload, 0, 16));
if ($gz === false) {
    fwrite(STDERR, "Decryption failed — FIXTURE_KEY does not match this fixture.\n");
    exit(1);
}

$sql = gzdecode($gz);
if ($sql === false || strlen($sql) < 1_000_000) {
    fwrite(STDERR, 'Fixture decompressed to '.(isset($sql) && $sql !== false ? strlen($sql) : 0)." bytes — looks wrong, aborting before any DB change.\n");
    exit(1);
}
unset($cipher, $gz);

// ---- Phase 2: restore ONLY if `stores` is missing or empty. ----

$tables = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'stores'"
)->fetchColumn();

if ((int) $tables >= 50) {
    echo "Legacy database `stores` already populated ({$tables} tables) — restore skipped.\n";
} else {
    echo 'Restoring legacy `stores` from fixture ('.number_format(strlen($sql) / 1024 / 1024, 1).' MB dump) '.((int) $tables === 0 ? '(database empty)' : '(database missing)')." ...\n";

    $pdo->exec('DROP DATABASE IF EXISTS `stores`');
    $pdo->exec('CREATE DATABASE `stores` CHARACTER SET latin1 COLLATE latin1_swedish_ci');
    $pdo->exec('USE `stores`');

    // Statement-per-line restore. The /*!40101 ... */ conditionals MUST run —
    // mysqldump/phpMyAdmin rely on them to set the session charset the dump
    // bytes are encoded in. Comment banners may SHARE a chunk with the real
    // statement that follows ("-- Table structure ...\nCREATE TABLE ...");
    // only leading comment lines are stripped, never the statement itself —
    // skipping every "--"-prefixed chunk silently dropped all 54 CREATEs.
    $done = 0;
    foreach (preg_split('/;\r?\n/', $sql) ?: [] as $raw) {
        $sql1 = trim((string) preg_replace('/^(?:\s|--[^\n]*\n)+/', '', $raw));
        if ($sql1 === '') {
            continue;
        }
        $pdo->exec($sql1);
        $done++;
    }
    unset($sql);

    $tables = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'stores'"
    )->fetchColumn();
    echo "Executed {$done} statements; legacy `stores` now has {$tables} tables.\n";

    if ($tables < 50) {
        fwrite(STDERR, "Legacy restore looks wrong: only {$tables} tables present.\n");
        exit(1);
    }
}

// ---- Phase 3: ensure the empty app-side test database exists (the suite
// self-heals it, but creating it here keeps first-run errors legible). ----

$pdo->exec('CREATE DATABASE IF NOT EXISTS `stores_laravel_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

echo "Legacy fixture ready ({$tables} tables); stores_laravel_test present.\n";
