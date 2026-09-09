<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_excess_sub` as `excess_items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excess_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('essubid')->unsigned()->autoIncrement()->primary();
            $table->integer('esid')->unsigned()->nullable();
            $table->integer('whid')->unsigned()->nullable();
            $table->integer('binid')->unsigned()->nullable();
            $table->integer('subbinid')->unsigned()->nullable();
            $table->integer('upsex')->unsigned()->nullable();
            $table->decimal('qtyex', 10, 3)->nullable();
            $table->integer('upssh')->unsigned()->nullable();
            $table->decimal('qtysh', 10, 3)->nullable();
            $table->integer('balups')->unsigned()->nullable();
            $table->decimal('balqty', 10, 3)->nullable();
            $table->integer('rowid')->unsigned()->nullable();
            $table->index(['essubid'], 'ix_excess_items_1');
            $table->index(['esid'], 'ix_excess_items_2');
            $table->index(['whid'], 'ix_excess_items_3');
            $table->index(['binid'], 'ix_excess_items_4');
            $table->index(['subbinid'], 'ix_excess_items_5');
            $table->index(['rowid'], 'ix_excess_items_6');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excess_items');
    }
};
