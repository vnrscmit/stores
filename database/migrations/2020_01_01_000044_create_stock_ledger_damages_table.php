<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_stldg_damage` as `stock_ledger_damages`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger_damages', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('stld_id')->unsigned()->autoIncrement()->primary();
            $table->string('stld_trtype', 200)->nullable();
            $table->string('stld_trsubtype', 200)->nullable();
            $table->integer('stld_trid')->unsigned()->nullable();
            $table->string('stld_trpartyid', 200)->nullable();
            $table->date('stld_trdate')->nullable();
            $table->integer('stld_trclassid')->unsigned()->nullable();
            $table->integer('stld_tritemid')->unsigned()->nullable();
            $table->integer('stld_whid')->unsigned()->nullable();
            $table->integer('stld_binid')->unsigned()->nullable();
            $table->integer('stld_subbinid')->unsigned()->nullable();
            $table->integer('stld_opups')->unsigned()->default('0');
            $table->decimal('stld_opqty', 10, 3)->default('0.000');
            $table->integer('stld_trups')->unsigned()->nullable();
            $table->decimal('stld_trqty', 10, 3)->nullable();
            $table->integer('stld_balups')->unsigned()->nullable();
            $table->decimal('stld_balqty', 10, 3)->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->string('orstatus', 10)->nullable();
            $table->index(['stld_trtype'], 'ix_stock_ledger_damages_1');
            $table->index(['stld_trsubtype'], 'ix_stock_ledger_damages_2');
            $table->index(['stld_trid'], 'ix_stock_ledger_damages_3');
            $table->index(['stld_trpartyid'], 'ix_stock_ledger_damages_4');
            $table->index(['stld_trdate'], 'ix_stock_ledger_damages_5');
            $table->index(['stld_trclassid'], 'ix_stock_ledger_damages_6');
            $table->index(['stld_tritemid'], 'ix_stock_ledger_damages_7');
            $table->index(['stld_whid'], 'ix_stock_ledger_damages_8');
            $table->index(['stld_binid'], 'ix_stock_ledger_damages_9');
            $table->index(['stld_subbinid'], 'ix_stock_ledger_damages_10');
            $table->index(['stld_opups'], 'ix_stock_ledger_damages_11');
            $table->index(['stld_opqty'], 'ix_stock_ledger_damages_12');
            $table->index(['stld_trups'], 'ix_stock_ledger_damages_13');
            $table->index(['stld_trqty'], 'ix_stock_ledger_damages_14');
            $table->index(['stld_balups'], 'ix_stock_ledger_damages_15');
            $table->index(['stld_balqty'], 'ix_stock_ledger_damages_16');
            $table->index(['yearcode'], 'ix_stock_ledger_damages_17');
            $table->index(['orstatus'], 'ix_stock_ledger_damages_18');
            $table->index('stld_trid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger_damages');
    }
};
