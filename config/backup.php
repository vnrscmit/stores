<?php

return [

    /*
    |------------------------------------------------------------------
    | Nightly off-site backup (db:backup, scheduled 02:00 in
    | routes/console.php, driven by the Task Scheduler heartbeat).
    |------------------------------------------------------------------
    |
    | destination   Where dumps land. The default (storage/backups) is
    |               same-disk and NOT off-site — point BACKUP_DIR at a
    |               second disk or a network share in production.
    | retention_days  Dumps older than this are pruned after a fully
    |               successful run (a failed run never deletes).
    | mysqldump     The mysqldump binary. "mysqldump" resolves via PATH;
    |               XAMPP boxes give the full path.
    | timeout       Per-dump Process timeout in seconds.
    |
    */

    'destination' => env('BACKUP_DIR') ?: storage_path('backups'),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

];
