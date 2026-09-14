<?php

namespace App\Console\Commands;

use App\Support\ParallelDatabase;
use Illuminate\Console\Command;

/**
 * Manage the parallel-testing database template and per-worker clones.
 *
 *   tests:parallel-db              Build/repair the template DB, then pre-clone workers
 *   tests:parallel-db --rebuild    Force a template rebuild from the legacy pipeline
 *   tests:parallel-db --cleanup    Drop all worker clones (keeps the template)
 */
class TestsParallelDb extends Command
{
    protected $signature = 'tests:parallel-db
                            {--rebuild : Force rebuilding the template database from the legacy pipeline}
                            {--processes=0 : Pre-clone this many worker DBs (0 = auto: CPU count)}
                            {--cleanup : Drop all worker clone databases}';

    protected $description = 'Prepare per-worker test databases for parallel (ParaTest) runs';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            $dropped = ParallelDatabase::cleanupWorkers();

            $this->info($dropped === []
                ? 'No worker clones to drop.'
                : 'Dropped: '.implode(', ', $dropped));

            return self::SUCCESS;
        }

        ParallelDatabase::ensureTemplate((bool) $this->option('rebuild'));
        $this->info('Template database ready.');

        $processes = (int) $this->option('processes');
        if ($processes === 0) {
            $processes = (int) (getenv('NUMBER_OF_PROCESSORS') ?: 4);
        }

        $created = ParallelDatabase::preCloneWorkers($processes);

        $this->info($created === []
            ? "All {$processes} worker clones already warm."
            : 'Created: '.implode(', ', $created));

        $this->line('Run the suite with: php artisan test --parallel');

        return self::SUCCESS;
    }
}
