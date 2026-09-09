<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_classification` as `classifications`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classifications', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('classification_id')->unsigned()->autoIncrement()->primary();
            $table->string('classification', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classifications');
    }
};
