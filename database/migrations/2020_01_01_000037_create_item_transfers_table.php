<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_iitr` as `item_transfers`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_transfers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('iitr_id')->unsigned()->autoIncrement()->primary();
            $table->integer('tcode')->unsigned()->nullable();
            $table->date('tdate')->nullable();
            $table->integer('iitr_code')->unsigned()->nullable();
            $table->integer('classification_id')->unsigned()->nullable();
            $table->integer('items_id_from')->unsigned()->nullable();
            $table->integer('items_id_to')->unsigned()->nullable();
            $table->string('uom_from', 50)->nullable();
            $table->string('uom_to', 50)->nullable();
            $table->string('typ', 20)->nullable();
            $table->integer('slocno')->unsigned()->nullable();
            $table->integer('slocno_d')->unsigned()->nullable();
            $table->text('remarks')->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->tinyInteger('iitrflg')->unsigned()->nullable();
            $table->index(['iitr_id'], 'ix_item_transfers_1');
            $table->index(['classification_id'], 'ix_item_transfers_2');
            $table->index(['items_id_from'], 'ix_item_transfers_3');
            $table->index(['items_id_to'], 'ix_item_transfers_4');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_transfers');
    }
};
