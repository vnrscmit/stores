<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Nightly off-site backup (see docs/DEPLOYMENT.md): both databases via
// mysqldump into BACKUP_DIR, retention pruning included. The Windows
// Task Scheduler heartbeat runs `schedule:run` every minute.
Schedule::command('db:backup')->dailyAt('02:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
