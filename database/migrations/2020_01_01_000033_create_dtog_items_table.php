<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_dtog_sub` as `dtog_items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtog_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('dgsubid')->unsigned()->autoIncrement()->primary();
            $table->integer('did')->unsigned()->nullable();
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
            $table->integer('balqty')->unsigned()->nullable();
            $table->integer('rowid')->unsigned()->nullable();
            $table->index(['did'], 'ix_dtog_items_1');
            $table->index(['classification_id'], 'ix_dtog_items_2');
            $table->index(['items_id'], 'ix_dtog_items_3');
            $table->index(['whid'], 'ix_dtog_items_4');
            $table->index(['binid'], 'ix_dtog_items_5');
            $table->index(['subbinid'], 'ix_dtog_items_6');
            $table->index(['rowid'], 'ix_dtog_items_7');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtog_items');
    }
};
