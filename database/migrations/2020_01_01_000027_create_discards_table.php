<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_discard` as `discards`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discards', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('tid')->unsigned()->autoIncrement()->primary();
            $table->integer('tcode')->nullable();
            $table->date('tdate')->nullable();
            $table->integer('dd_code')->nullable();
            $table->integer('p_id')->nullable();
            $table->string('drno', 50)->nullable();
            $table->string('party_name', 250)->nullable();
            $table->text('address')->nullable();
            $table->string('address1', 100)->nullable();
            $table->string('city', 50)->nullable();
            $table->integer('pin')->unsigned()->nullable();
            $table->string('state', 50)->nullable();
            $table->bigInteger('phoneno')->unsigned()->nullable();
            $table->string('tmode', 50)->nullable();
            $table->string('tname', 50)->nullable();
            $table->integer('lrno')->nullable();
            $table->string('vno', 50)->nullable();
            $table->string('cname', 10)->nullable();
            $table->integer('dcno')->nullable();
            $table->string('pmode', 50)->nullable();
            $table->string('pname', 250)->nullable();
            $table->tinyInteger('ddflg')->nullable();
            $table->string('ddrole', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->string('rettyp', 20)->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->integer('ncode')->unsigned()->nullable();
            $table->index(['p_id'], 'ix_discards_1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discards');
    }
};
