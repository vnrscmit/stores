<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 acceptance: schema exists, counts reconcile, key joins resolve,
 * auth consolidation sane, active fiscal year present.
 */
class Phase1Smoke extends Command
{
    protected $signature = 'phase1:smoke';

    protected $description = 'Phase 1 acceptance test over the migrated database';

    public function handle(): int
    {
        $checks = [];
        $fail = 0;

        $check = function (string $name, bool $ok, string $detail = '') use (&$checks, &$fail) {
            $checks[] = [$name, $ok ? 'PASS' : 'FAIL', $detail];
            if (! $ok) {
                $fail++;
            }
        };

        // Core tables exist.
        foreach (['users', 'items', 'warehouses', 'bins', 'sub_bins', 'stock_ledger_goods', 'e_indents', 'issues'] as $t) {
            $check("table {$t}", Schema::hasTable($t));
        }

        // Auth consolidation.
        if (Schema::hasTable('users')) {
            $users = DB::table('users')->count();
            $roles = DB::table('users')->selectRaw('role, COUNT(*) c')->groupBy('role')->pluck('c', 'role');
            $check('users > 0', $users > 0, number_format($users).' accounts');
            $check('roles present', isset($roles['admin'], $roles['operator'], $roles['eindent'], $roles['viewer']));
            $check('no plaintext passwords', (int) DB::table('users')->where('password', 'not like', '$2y$%')->count() === 0);
        }

        // Active fiscal year.
        if (Schema::hasTable('financial_years')) {
            $fy = DB::table('financial_years')->where('years_flg', '!=', 0)->where('years_status', 'a')->first();
            $check('active fiscal year', $fy !== null, $fy->year_name ?? '');
        }

        // Ledger joinability.
        if (Schema::hasTable('stock_ledger_goods') && Schema::hasTable('items')) {
            $orphan = (int) DB::table('stock_ledger_goods')
                ->leftJoin('items', 'stock_ledger_goods.stlg_tritemid', '=', 'items.items_id')
                ->whereNull('items.items_id')
                ->count();
            $check('ledger rows join items', $orphan === 0, $orphan.' orphan');
        }

        // Location hierarchy integrity. NULL whid = legacy sentinel 0
        // ("unassigned" bin) — legitimate; only non-null dangling refs fail.
        if (Schema::hasTable('bins') && Schema::hasTable('warehouses')) {
            $orphanBins = (int) DB::table('bins')
                ->leftJoin('warehouses', 'bins.whid', '=', 'warehouses.whid')
                ->whereNotNull('bins.whid')
                ->whereNull('warehouses.whid')
                ->count();
            $unassigned = (int) DB::table('bins')->whereNull('whid')->count();
            $check('bins join warehouses', $orphanBins === 0, $orphanBins.' dangling, '.$unassigned.' unassigned');
        }

        // FK constraints present.
        $fks = (int) DB::selectOne(
            "SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        )->c;
        $check('FK constraints', $fks > 0, $fks.' constraints');

        // Document counters seeded.
        if (Schema::hasTable('document_counters')) {
            $counters = (int) DB::table('document_counters')->count();
            $check('document counters', $counters > 0, $counters.' type/year rows');
        }

        $this->table(['check', 'status', 'detail'], $checks);

        if ($fail > 0) {
            $this->error("SMOKE FAILURES: {$fail}");

            return self::FAILURE;
        }

        $this->info('Phase 1 smoke: all green.');

        return self::SUCCESS;
    }
}
