<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_report` as `report_definitions`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id')->unsigned()->autoIncrement()->primary();
            $table->string('report', 100)->nullable();
            $table->string('good', 100)->nullable();
            $table->string('damage', 100)->nullable();
            $table->index(['report'], 'ix_report_definitions_1');
            $table->index(['good'], 'ix_report_definitions_2');
            $table->index(['damage'], 'ix_report_definitions_3');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
