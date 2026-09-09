<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tblarrival` as `arrivals`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrivals', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('arrival_id')->unsigned()->autoIncrement()->primary();
            $table->string('arrival_type', 100)->nullable();
            $table->integer('arrival_code')->nullable();
            $table->integer('arr_code')->unsigned()->nullable();
            $table->date('arrival_date')->nullable();
            $table->string('dcno', 50)->nullable();
            $table->string('invoiceno', 50)->nullable();
            $table->string('stnno', 50)->nullable();
            $table->string('stageret', 100)->nullable();
            $table->string('retid', 100)->nullable();
            $table->integer('party_id')->nullable();
            $table->string('porefno', 50)->nullable();
            $table->string('tmode', 100)->nullable();
            $table->string('trans_name', 100)->nullable();
            $table->string('trans_lorryrepno', 50)->nullable();
            $table->string('trans_vehno', 50)->nullable();
            $table->string('trans_paymode', 50)->nullable();
            $table->string('courier_name', 100)->nullable();
            $table->string('docket_no', 50)->nullable();
            $table->string('pname_byhand', 250)->nullable();
            $table->text('remarks')->nullable();
            $table->integer('arrtrflag')->unsigned()->nullable();
            $table->string('arr_role', 50)->nullable();
            $table->integer('ncode')->unsigned()->nullable();
            $table->string('type', 100)->nullable();
            $table->string('yearcode', 50)->nullable();
            $table->index(['party_id'], 'ix_arrivals_1');
            $table->index('party_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrivals');
    }
};
