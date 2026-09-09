<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_ciupdation` as `cycle_count_updates`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycle_count_updates', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('ciu_id')->unsigned()->autoIncrement()->primary();
            $table->integer('ci_id')->unsigned()->nullable();
            $table->integer('classification_id')->unsigned()->nullable();
            $table->integer('items_id')->unsigned()->nullable();
            $table->integer('whid')->unsigned()->nullable();
            $table->integer('binid')->unsigned()->nullable();
            $table->integer('subbinid')->unsigned()->nullable();
            $table->integer('ups_record')->unsigned()->nullable();
            $table->decimal('qty_record', 10, 3)->nullable();
            $table->integer('ups_act')->unsigned()->nullable();
            $table->decimal('qty_act', 10, 3)->nullable();
            $table->date('ciu_udate')->nullable();
            $table->text('remarks')->nullable();
            $table->tinyInteger('status')->unsigned()->nullable();
            $table->integer('rowid')->unsigned()->nullable();
            $table->index(['ciu_id'], 'ix_cycle_count_updates_1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_count_updates');
    }
};
