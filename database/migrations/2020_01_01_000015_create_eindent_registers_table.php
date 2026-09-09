<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_eindents` as `eindent_registers`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eindent_registers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('tid')->unsigned()->autoIncrement()->primary();
            $table->integer('code')->default('0');
            $table->date('date')->nullable();
            $table->integer('classification_id')->default('0');
            $table->integer('items_id')->default('0');
            $table->string('yearcode', 20)->default('');
            $table->index(['classification_id'], 'ix_eindent_registers_1');
            $table->index(['items_id'], 'ix_eindent_registers_2');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eindent_registers');
    }
};
