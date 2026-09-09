<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_issuestock` as `issue_stock_headers`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_stock_headers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id')->unsigned()->autoIncrement()->primary();
            $table->integer('code')->nullable();
            $table->date('tdate')->nullable();
            $table->integer('p_id')->nullable();
            $table->string('address', 50)->nullable();
            $table->integer('strno')->nullable();
            $table->date('strdate')->nullable();
            $table->string('mode', 50)->nullable();
            $table->string('tname', 50)->nullable();
            $table->integer('lrno')->nullable();
            $table->integer('vno')->nullable();
            $table->string('pmode', 50)->nullable();
            $table->string('cname', 50)->nullable();
            $table->integer('dno')->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->index(['p_id'], 'ix_issue_stock_headers_1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_stock_headers');
    }
};
