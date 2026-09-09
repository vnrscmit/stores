<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_warehouse` as `warehouses`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('whid')->unsigned()->autoIncrement()->primary();
            $table->string('perticulars', 100)->default('');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
