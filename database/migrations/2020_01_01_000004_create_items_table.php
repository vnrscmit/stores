<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_stores` as `items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('items_id')->unsigned()->autoIncrement()->primary();
            $table->integer('classification_id')->nullable();
            $table->string('stores_item', 100)->nullable();
            $table->string('uom', 100)->nullable();
            $table->string('srl_status', 100)->nullable();
            $table->decimal('srl', 10, 3)->nullable();
            $table->string('actstatus', 50)->default('Active');
            $table->string('vaietyname', 100)->nullable();
            $table->index(['classification_id'], 'ix_items_1');
            $table->index(['stores_item'], 'ix_items_2');
            $table->index(['uom'], 'ix_items_3');
            $table->index(['srl_status'], 'ix_items_4');
            $table->index(['srl'], 'ix_items_5');
            $table->index(['actstatus'], 'ix_items_6');
            $table->index(['vaietyname'], 'ix_items_7');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
