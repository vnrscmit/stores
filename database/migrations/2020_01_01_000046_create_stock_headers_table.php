<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_stock` as `stock_headers`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_headers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('sid')->unsigned()->autoIncrement()->primary();
            $table->date('tdate')->nullable();
            $table->integer('code')->nullable();
            $table->integer('stno')->nullable();
            $table->string('modeoftransit', 50)->nullable();
            $table->string('tname', 50)->nullable();
            $table->integer('lrno')->nullable();
            $table->integer('vno')->nullable();
            $table->string('pmode', 100)->nullable();
            $table->string('cname', 50)->nullable();
            $table->string('stf', 50)->nullable();
            $table->integer('p_id')->nullable();
            $table->integer('docketno')->nullable();
            $table->index(['p_id'], 'ix_stock_headers_1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_headers');
    }
};
