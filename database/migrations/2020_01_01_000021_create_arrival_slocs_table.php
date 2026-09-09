<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tblarr_sloc` as `arrival_slocs`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrival_slocs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('arrsloc_id')->unsigned()->autoIncrement()->primary();
            $table->string('arr_type', 100)->nullable();
            $table->integer('arr_tr_id')->unsigned()->nullable();
            $table->integer('arr_id')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('item_id')->nullable();
            $table->integer('whid')->nullable();
            $table->integer('binid')->nullable();
            $table->integer('subbin')->nullable();
            $table->decimal('qty_good', 10, 3)->nullable();
            $table->integer('ups_good')->nullable();
            $table->decimal('qty_damage', 10, 3)->nullable();
            $table->integer('ups_damage')->nullable();
            $table->string('type', 50)->nullable();
            $table->integer('rowid')->unsigned()->nullable();
            $table->index(['arr_tr_id'], 'ix_arrival_slocs_1');
            $table->index(['arr_id'], 'ix_arrival_slocs_2');
            $table->index(['classification_id'], 'ix_arrival_slocs_3');
            $table->index(['item_id'], 'ix_arrival_slocs_4');
            $table->index(['whid'], 'ix_arrival_slocs_5');
            $table->index(['binid'], 'ix_arrival_slocs_6');
            $table->index(['subbin'], 'ix_arrival_slocs_7');
            $table->index(['rowid'], 'ix_arrival_slocs_8');
            $table->index('arr_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrival_slocs');
    }
};
