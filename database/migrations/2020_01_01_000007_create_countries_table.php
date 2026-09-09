<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_country` as `countries`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('country_id')->autoIncrement()->primary();
            $table->string('country', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
