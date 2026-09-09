<?php

$attempts = [
    ['dsn' => 'mysql:host=127.0.0.1;port=3306', 'label' => 'TCP 127.0.0.1'],
    ['dsn' => 'mysql:host=localhost;port=3306', 'label' => 'localhost (named pipe fallback)'],
    ['dsn' => 'mysql:host=localhost', 'label' => 'localhost default port'],
];

foreach ($attempts as $a) {
    $t0 = microtime(true);
    try {
        $pdo = new PDO($a['dsn'], 'root', '', [
            PDO::ATTR_TIMEOUT => 15,
            PDO::ERRMODE_EXCEPTION => true,
        ]);
        $v = $pdo->query('SELECT VERSION()')->fetchColumn();
        printf("%-34s OK %s (%.1fs)\n", $a['label'], $v, microtime(true) - $t0);
        foreach ($pdo->query('SHOW DATABASES') as $r) {
            echo '   db: '.$r['Database']."\n";
        }
    } catch (Throwable $e) {
        printf("%-34s FAIL after %.1fs: %s\n", $a['label'], microtime(true) - $t0, $e->getMessage());
    }
}
