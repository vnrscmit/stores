<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_bin` as `bins`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bins', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('binid')->unsigned()->autoIncrement()->primary();
            $table->integer('whid')->unsigned()->nullable();
            $table->string('binname', 100)->nullable();
            $table->index(['whid'], 'ix_bins_1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bins');
    }
};
