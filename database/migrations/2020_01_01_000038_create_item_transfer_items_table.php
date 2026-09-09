<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_iitr_sub` as `item_transfer_items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_transfer_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('iitrsub_id')->unsigned()->autoIncrement()->primary();
            $table->integer('iitr_id')->unsigned()->nullable();
            $table->integer('classification_id')->unsigned()->nullable();
            $table->integer('items_id')->unsigned()->nullable();
            $table->string('uom', 20)->nullable();
            $table->integer('whid')->unsigned()->nullable();
            $table->integer('binid')->unsigned()->nullable();
            $table->integer('subbinid')->unsigned()->nullable();
            $table->integer('ups_from')->unsigned()->nullable();
            $table->decimal('qty_from', 10, 3)->nullable();
            $table->integer('ups_to')->unsigned()->nullable();
            $table->decimal('qty_to', 10, 3)->nullable();
            $table->integer('balups')->unsigned()->nullable();
            $table->decimal('balqty', 10, 3)->nullable();
            $table->integer('rowid')->unsigned()->nullable();
            $table->integer('rowid_to')->unsigned()->nullable();
            $table->index(['iitrsub_id'], 'ix_item_transfer_items_1');
            $table->index(['iitr_id'], 'ix_item_transfer_items_2');
            $table->index(['classification_id'], 'ix_item_transfer_items_3');
            $table->index(['items_id'], 'ix_item_transfer_items_4');
            $table->index(['whid'], 'ix_item_transfer_items_5');
            $table->index(['binid'], 'ix_item_transfer_items_6');
            $table->index(['subbinid'], 'ix_item_transfer_items_7');
            $table->index(['rowid'], 'ix_item_transfer_items_8');
            $table->index(['rowid_to'], 'ix_item_transfer_items_9');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_transfer_items');
    }
};
