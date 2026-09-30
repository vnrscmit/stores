<?php

/**
 * Rebuild the encrypted CI fixture from a canonical legacy dump.
 *
 *   php tests/support/fixture_rebuild.php [path-to-dump.sql]
 *
 * Default source: storesd.sql at the repo root — the original legacy
 * database dump the whole migration was built from (see
 * docs/DEPLOYMENT.md). The dump is gzipped, then encrypted with
 * aes-256-cbc (key = FIXTURE_KEY env, no KDF — the key is high-entropy
 * hex) and written base64 to database/fixtures/legacy-stores.sql.gz.enc.
 *
 * Everything happens in PHP with repo-relative paths: the openssl CLI on
 * Windows proved unreliable for this (silent path/cwd mismatches), and the
 * decrypt side is the PDO-based tests/support/ci_fixture_restore.php,
 * which is the only consumer that matters.
 *
 * Verification is built in: the script re-reads the written fixture and
 * decrypts/decompresses it back, comparing sha256 against the gzipped
 * plaintext — the command fails loudly on any mismatch.
 */
if (getenv('FIXTURE_KEY') === false || getenv('FIXTURE_KEY') === '' || ! preg_match('/^[0-9a-f]{48}$/', (string) getenv('FIXTURE_KEY'))) {
    fwrite(STDERR, "FIXTURE_KEY env must be set to the 48-char hex key (see database/fixtures/FIXTURE_KEY.txt, gitignored).\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$source = $argv[1] ?? $root.'/storesd.sql';
$target = $root.'/database/fixtures/legacy-stores.sql.gz.enc';

if (! is_file($source)) {
    fwrite(STDERR, "Source dump not found: {$source}\n");
    exit(1);
}

$key = (string) getenv('FIXTURE_KEY');

// 1. Read + gzip the plaintext dump.
$sql = (string) file_get_contents($source);
echo 'Source: '.$source.' ('.number_format(strlen($sql) / 1024 / 1024, 1)." MB)\n";
$gz = (string) gzencode($sql, 9);
echo 'Gzipped: '.number_format(strlen($gz) / 1024 / 1024, 1)." MB\n";

// 2. Encrypt (raw, random IV prefixed to the ciphertext) + base64 for a
//    text-safe artifact, and write it.
$iv = (string) openssl_random_pseudo_bytes(16);
$cipher = openssl_encrypt($gz, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
if ($cipher === false) {
    fwrite(STDERR, 'openssl_encrypt failed: '.openssl_error_string()."\n");
    exit(1);
}
$encoded = base64_encode($iv.$cipher);
$bytes = file_put_contents($target, $encoded);
if ($bytes === false) {
    fwrite(STDERR, "Failed to write {$target}\n");
    exit(1);
}
echo 'Wrote: '.$target.' ('.number_format($bytes / 1024 / 1024, 1)." MB)\n";

// 3. Verify by reading the artifact back through the exact restore path.
$round = base64_decode((string) file_get_contents($target), true);
if ($round === false || strlen($round) < 17) {
    fwrite(STDERR, "Verify failed: artifact is not valid base64/payload.\n");
    exit(1);
}
$roundGz = openssl_decrypt(substr($round, 16), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr($round, 0, 16));
if ($roundGz === false) {
    fwrite(STDERR, 'Verify failed: '.openssl_error_string()."\n");
    exit(1);
}
$roundSql = gzdecode($roundGz);
if ($roundSql === false || strlen($roundSql) !== strlen($sql)) {
    fwrite(STDERR, "Verify failed: decompressed length mismatch.\n");
    exit(1);
}
if (hash('sha256', $roundSql) !== hash('sha256', $sql)) {
    fwrite(STDERR, "Verify failed: sha256 mismatch after roundtrip.\n");
    exit(1);
}

echo 'VERIFY OK: sha256 '.hash('sha256', $sql)." — the fixture decrypts to the source dump byte-for-byte.\n";
