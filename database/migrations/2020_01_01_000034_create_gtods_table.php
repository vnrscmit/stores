<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_gtod` as `gtods`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gtods', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('gid')->unsigned()->autoIncrement()->primary();
            $table->integer('code')->nullable();
            $table->date('date')->nullable();
            $table->integer('gcode')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('items_id')->nullable();
            $table->string('uom', 10)->nullable();
            $table->integer('noofbins')->unsigned()->nullable();
            $table->text('remarks')->nullable();
            $table->tinyInteger('gdflg')->default('0');
            $table->string('yearcode', 20)->nullable();
            $table->integer('ncode')->unsigned()->nullable();
            $table->integer('party_id')->unsigned()->nullable();
            $table->index(['classification_id'], 'ix_gtods_1');
            $table->index(['items_id'], 'ix_gtods_2');
            $table->index(['party_id'], 'ix_gtods_3');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gtods');
    }
};
