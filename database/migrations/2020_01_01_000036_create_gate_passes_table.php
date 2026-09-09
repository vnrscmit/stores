<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_gate` as `gate_passes`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_passes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('gpid')->unsigned()->autoIncrement()->primary();
            $table->integer('gpcode')->unsigned()->nullable();
            $table->string('trid', 200)->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->index(['trid'], 'ix_gate_passes_1');
            $table->index('trid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_passes');
    }
};
