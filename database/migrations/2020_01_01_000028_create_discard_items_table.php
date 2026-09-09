<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_discard_sub` as `discard_items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discard_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('did')->unsigned()->autoIncrement()->primary();
            $table->integer('calssification_id')->nullable();
            $table->integer('items_id')->nullable();
            $table->string('uom', 50)->nullable();
            $table->integer('ups')->nullable();
            $table->decimal('qty', 10, 3)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('remark', 100)->nullable();
            $table->string('returnable', 10)->nullable();
            $table->integer('did_s')->nullable();
            $table->index(['calssification_id'], 'ix_discard_items_1');
            $table->index(['items_id'], 'ix_discard_items_2');
            $table->index(['ups'], 'ix_discard_items_3');
            $table->index(['did_s'], 'ix_discard_items_4');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discard_items');
    }
};
