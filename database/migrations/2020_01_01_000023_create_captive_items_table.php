<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_captivesub` as `captive_items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captive_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('eid')->unsigned()->autoIncrement()->primary();
            $table->integer('items_id')->nullable();
            $table->integer('classification_id')->nullable();
            $table->string('uom', 50)->nullable();
            $table->integer('ups')->nullable();
            $table->decimal('qty', 10, 3)->nullable();
            $table->string('remarks', 100)->nullable();
            $table->string('type', 50)->nullable();
            $table->integer('id_in')->nullable();
            $table->index(['items_id'], 'ix_captive_items_1');
            $table->index(['classification_id'], 'ix_captive_items_2');
            $table->index(['uom'], 'ix_captive_items_3');
            $table->index(['ups'], 'ix_captive_items_4');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captive_items');
    }
};
