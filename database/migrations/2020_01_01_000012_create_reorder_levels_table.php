<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_order` as `reorder_levels`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reorder_levels', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('orderid')->unsigned()->autoIncrement()->primary();
            $table->integer('tcode')->nullable();
            $table->date('tdate')->nullable();
            $table->integer('classification_id')->unsigned()->nullable();
            $table->integer('items_id')->unsigned()->nullable();
            $table->decimal('olevel', 10, 3)->nullable();
            $table->tinyInteger('oflg')->unsigned()->nullable();
            $table->index(['classification_id'], 'ix_reorder_levels_1');
            $table->index(['items_id'], 'ix_reorder_levels_2');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reorder_levels');
    }
};
