<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tblyears` as `financial_years`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_years', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('yearsid')->unsigned()->autoIncrement()->primary();
            $table->string('year_name', 50)->nullable();
            $table->string('years', 50)->default('');
            $table->integer('years_flg')->unsigned()->nullable();
            $table->string('years_status', 5)->nullable();
            $table->integer('year1')->unsigned()->nullable();
            $table->integer('year2')->unsigned()->nullable();
            $table->string('ycode', 10)->nullable();
            $table->index(['year_name'], 'ix_financial_years_1');
            $table->index(['years'], 'ix_financial_years_2');
            $table->index(['years_flg'], 'ix_financial_years_3');
            $table->index(['years_status'], 'ix_financial_years_4');
            $table->index(['year1'], 'ix_financial_years_5');
            $table->index(['year2'], 'ix_financial_years_6');
            $table->index(['ycode'], 'ix_financial_years_7');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_years');
    }
};
