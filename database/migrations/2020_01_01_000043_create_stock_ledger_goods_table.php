<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_stldg_good` as `stock_ledger_goods`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger_goods', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('stlg_id')->unsigned()->autoIncrement()->primary();
            $table->string('stlg_trtype', 200)->nullable();
            $table->string('stlg_trsubtype', 200)->nullable();
            $table->integer('stlg_trid')->unsigned()->nullable();
            $table->string('stlg_trpartyid', 200)->nullable();
            $table->date('stlg_trdate')->nullable();
            $table->integer('stlg_trclassid')->unsigned()->nullable();
            $table->integer('stlg_tritemid')->unsigned()->nullable();
            $table->integer('stlg_whid')->unsigned()->nullable();
            $table->integer('stlg_binid')->unsigned()->nullable();
            $table->integer('stlg_subbinid')->unsigned()->nullable();
            $table->integer('stlg_opups')->unsigned()->nullable();
            $table->decimal('stlg_opqty', 10, 3)->nullable();
            $table->integer('stlg_trups')->unsigned()->nullable();
            $table->decimal('stlg_trqty', 10, 3)->nullable();
            $table->integer('stlg_balups')->unsigned()->nullable();
            $table->decimal('stlg_balqty', 10, 3)->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->string('orstatus', 10)->nullable();
            $table->date('ordate')->nullable();
            $table->index(['stlg_trtype'], 'ix_stock_ledger_goods_1');
            $table->index(['stlg_trsubtype'], 'ix_stock_ledger_goods_2');
            $table->index(['stlg_trid'], 'ix_stock_ledger_goods_3');
            $table->index(['stlg_trpartyid'], 'ix_stock_ledger_goods_4');
            $table->index(['stlg_trdate'], 'ix_stock_ledger_goods_5');
            $table->index(['stlg_trclassid'], 'ix_stock_ledger_goods_6');
            $table->index(['stlg_tritemid'], 'ix_stock_ledger_goods_7');
            $table->index(['stlg_whid'], 'ix_stock_ledger_goods_8');
            $table->index(['stlg_binid'], 'ix_stock_ledger_goods_9');
            $table->index(['stlg_subbinid'], 'ix_stock_ledger_goods_10');
            $table->index(['stlg_opups'], 'ix_stock_ledger_goods_11');
            $table->index(['stlg_opqty'], 'ix_stock_ledger_goods_12');
            $table->index(['stlg_trups'], 'ix_stock_ledger_goods_13');
            $table->index(['stlg_trqty'], 'ix_stock_ledger_goods_14');
            $table->index(['stlg_balups'], 'ix_stock_ledger_goods_15');
            $table->index(['stlg_balqty'], 'ix_stock_ledger_goods_16');
            $table->index(['yearcode'], 'ix_stock_ledger_goods_17');
            $table->index(['orstatus'], 'ix_stock_ledger_goods_18');
            $table->index(['ordate'], 'ix_stock_ledger_goods_19');
            $table->index('stlg_trid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger_goods');
    }
};
