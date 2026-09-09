<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_ci` as `cycle_counts`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycle_counts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('ci_id')->unsigned()->autoIncrement()->primary();
            $table->date('ci_tdate')->nullable();
            $table->integer('ci_code')->unsigned()->nullable();
            $table->text('classification_id')->nullable();
            $table->text('items_id')->nullable();
            $table->date('ci_udate')->nullable();
            $table->tinyInteger('ci_upflg')->unsigned()->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->index(['ci_id'], 'ix_cycle_counts_1');
            $table->index('classification_id');
            $table->index('items_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_counts');
    }
};
