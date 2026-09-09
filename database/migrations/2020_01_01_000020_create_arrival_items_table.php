<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tblarrival_sub` as `arrival_items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrival_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('arrsub_id')->unsigned()->autoIncrement()->primary();
            $table->integer('arrival_id')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('item_id')->nullable();
            $table->decimal('qty_per_dc', 10, 3)->nullable();
            $table->integer('ups_per_dc')->nullable();
            $table->decimal('qty_good', 10, 3)->nullable();
            $table->integer('ups_good')->nullable();
            $table->decimal('qty_damage', 10, 3)->nullable();
            $table->integer('ups_damage')->nullable();
            $table->decimal('exsh_qty', 10, 3)->nullable();
            $table->integer('exsh_ups')->nullable();
            $table->integer('noofbin_good')->nullable();
            $table->integer('noofbin_damage')->nullable();
            $table->string('uom', 50)->nullable();
            $table->text('remarks')->nullable();
            $table->string('type', 50)->nullable();
            $table->index(['arrival_id'], 'ix_arrival_items_1');
            $table->index(['classification_id'], 'ix_arrival_items_2');
            $table->index(['item_id'], 'ix_arrival_items_3');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrival_items');
    }
};
