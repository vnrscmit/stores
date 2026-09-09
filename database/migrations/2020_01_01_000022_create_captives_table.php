<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_captive` as `captives`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captives', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('tid')->unsigned()->autoIncrement()->primary();
            $table->integer('code')->nullable();
            $table->date('tdate')->nullable();
            $table->integer('cc_code')->unsigned()->nullable();
            $table->integer('party_id')->nullable();
            $table->bigInteger('contactno')->nullable();
            $table->string('tmode', 100)->nullable();
            $table->string('tname', 100)->nullable();
            $table->integer('lrno')->nullable();
            $table->string('vno', 100)->nullable();
            $table->string('pmode', 100)->nullable();
            $table->string('cname', 50)->nullable();
            $table->integer('docketno')->nullable();
            $table->string('pname', 100)->nullable();
            $table->string('party_name', 250)->nullable();
            $table->string('address', 100)->nullable();
            $table->string('address1', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->integer('pin')->nullable();
            $table->string('state', 50)->nullable();
            $table->integer('ccflg')->nullable();
            $table->string('ccrole', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->string('rettyp', 50)->nullable();
            $table->integer('ncode')->nullable();
            $table->string('yearcode', 50)->nullable();
            $table->index(['party_id'], 'ix_captives_1');
            $table->index('party_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captives');
    }
};
