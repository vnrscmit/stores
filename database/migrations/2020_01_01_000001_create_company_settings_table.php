<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_parameters` as `company_settings`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id')->unsigned()->autoIncrement()->primary();
            $table->string('company_name', 100)->nullable();
            $table->text('address')->nullable();
            $table->text('plant')->nullable();
            $table->string('licence_no', 50)->nullable();
            $table->string('cst_no', 50)->nullable();
            $table->string('tin', 100)->nullable();
            $table->string('logo', 250)->nullable();
            $table->string('ccity', 100)->nullable();
            $table->integer('cpin')->nullable();
            $table->bigInteger('cphone')->nullable();
            $table->bigInteger('cphone1')->unsigned()->nullable();
            $table->string('cstate', 50)->nullable();
            $table->string('pcity', 50)->nullable();
            $table->integer('ppin')->nullable();
            $table->integer('pstd')->nullable();
            $table->bigInteger('pphone')->nullable();
            $table->bigInteger('pphone1')->unsigned()->nullable();
            $table->integer('cstd')->nullable();
            $table->string('pstate', 50)->nullable();
            $table->string('pan', 20)->nullable();
            $table->string('gst', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
