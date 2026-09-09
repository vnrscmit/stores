<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_ieindent_sub` as `e_indent_items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_indent_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('eid')->unsigned()->autoIncrement()->primary();
            $table->integer('id_in')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('items_id')->nullable();
            $table->string('uom', 50)->nullable();
            $table->integer('ups')->nullable();
            $table->decimal('qty', 10, 3)->nullable();
            $table->integer('tid')->nullable();
            $table->index(['id_in'], 'ix_e_indent_items_1');
            $table->index(['classification_id'], 'ix_e_indent_items_2');
            $table->index(['items_id'], 'ix_e_indent_items_3');
            $table->index(['tid'], 'ix_e_indent_items_4');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_indent_items');
    }
};
