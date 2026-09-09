<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_subbin` as `sub_bins`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_bins', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('sid')->unsigned()->autoIncrement()->primary();
            $table->integer('sname')->nullable();
            $table->integer('binid')->nullable();
            $table->integer('whid')->unsigned()->nullable();
            $table->string('status', 50)->nullable();
            $table->unique(['sname', 'binid', 'whid'], 'ix_sub_bins_1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_bins');
    }
};
