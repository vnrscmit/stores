<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_dtog` as `dtogs`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtogs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('did')->unsigned()->autoIncrement()->primary();
            $table->integer('code')->nullable();
            $table->date('date')->nullable();
            $table->integer('dcode')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('items_id')->nullable();
            $table->string('uom', 10)->nullable();
            $table->integer('noofbins')->unsigned()->nullable();
            $table->text('remarks')->nullable();
            $table->tinyInteger('dgflg')->default('0');
            $table->string('yearcode', 20)->nullable();
            $table->integer('ncode')->unsigned()->nullable();
            $table->index(['classification_id'], 'ix_dtogs_1');
            $table->index(['items_id'], 'ix_dtogs_2');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtogs');
    }
};
