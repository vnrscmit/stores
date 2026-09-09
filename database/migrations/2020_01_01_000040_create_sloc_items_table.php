<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_sloc_sub` as `sloc_items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sloc_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('slocsubid')->unsigned()->autoIncrement()->primary();
            $table->integer('slocid')->unsigned()->nullable();
            $table->integer('classification_id')->unsigned()->nullable();
            $table->integer('items_id')->unsigned()->nullable();
            $table->integer('whid')->unsigned()->nullable();
            $table->integer('binid')->unsigned()->nullable();
            $table->integer('subbinid')->unsigned()->nullable();
            $table->integer('opups')->unsigned()->nullable();
            $table->decimal('opqty', 10, 3)->nullable();
            $table->integer('ups')->unsigned()->nullable();
            $table->decimal('qty', 10, 3)->nullable();
            $table->integer('balups')->unsigned()->nullable();
            $table->decimal('balqty', 10, 3)->nullable();
            $table->integer('rowid')->unsigned()->nullable();
            $table->index(['slocsubid'], 'ix_sloc_items_1');
            $table->index(['slocid'], 'ix_sloc_items_2');
            $table->index(['classification_id'], 'ix_sloc_items_3');
            $table->index(['items_id'], 'ix_sloc_items_4');
            $table->index(['whid'], 'ix_sloc_items_5');
            $table->index(['binid'], 'ix_sloc_items_6');
            $table->index(['subbinid'], 'ix_sloc_items_7');
            $table->index(['rowid'], 'ix_sloc_items_8');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sloc_items');
    }
};
