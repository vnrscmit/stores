<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\StockLedgerGood;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class DebugLedger extends Command
{
    protected $signature = 'debug:ledger';

    protected $description = 'Inspect the app connection, table counts, and a sample item/ledger chain';

    public function handle(): int
    {
        $pdo = DB::connection()->getPdo();

        $this->info('Connection: '.DB::connection()->getName());
        $this->info('Server: '.$pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
        $this->info('Database: '.$pdo->query('SELECT DATABASE()')->fetchColumn());

        foreach (['items', 'stock_ledger_goods', 'issues', 'captives', 'audit_logs'] as $table) {
            $this->line(sprintf('  %-22s %d', $table, DB::table($table)->count()));
        }

        $item = DB::table('items')->orderByDesc('items_id')->first();
        if ($item === null) {
            $this->error('No rows in items — run migrate:fresh and the legacy import commands.');

            return 1;
        }

        $this->info("Sample item {$item->items_id}: {$item->stores_item}");

        $rows = DB::table('stock_ledger_goods')
            ->where('stlg_tritemid', $item->items_id)
            ->orderByDesc('stlg_id')
            ->limit(5)
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No ledger rows for the sample item.');
        }

        foreach ($rows as $r) {
            $this->line(sprintf(
                '  stlg_id=%d trtype=%s/%s trqty=%s balqty=%s wh=%d bin=%d subbin=%d trid=%d',
                $r->stlg_id,
                $r->stlg_trtype,
                $r->stlg_trsubtype ?? '-',
                $r->stlg_trqty,
                $r->stlg_balqty,
                $r->stlg_whid,
                $r->stlg_binid,
                $r->stlg_subbinid ?? 0,
                $r->stlg_trid
            ));
        }

        $itemModel = new Item;
        $ledgerModel = new StockLedgerGood;
        $this->info('Item model table/connection: '.$itemModel->getTable().' / '.($itemModel->getConnectionName() ?? 'default'));
        $this->info('Ledger model table/connection: '.$ledgerModel->getTable().' / '.($ledgerModel->getConnectionName() ?? 'default'));

        return 0;
    }
}
