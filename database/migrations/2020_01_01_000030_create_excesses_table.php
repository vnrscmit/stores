<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_excess` as `excesses`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excesses', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('code')->nullable();
            $table->date('tdate')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('items_id')->nullable();
            $table->integer('tid')->unsigned()->autoIncrement()->primary();
            $table->string('uom', 20)->nullable();
            $table->text('remarks')->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->integer('ups')->unsigned()->nullable();
            $table->decimal('qty', 10, 3)->nullable();
            $table->integer('escode')->unsigned()->nullable();
            $table->string('typ', 20)->nullable();
            $table->tinyInteger('esflg')->unsigned()->nullable();
            $table->integer('ncode')->unsigned()->nullable();
            $table->index(['classification_id'], 'ix_excesses_1');
            $table->index(['items_id'], 'ix_excesses_2');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excesses');
    }
};
