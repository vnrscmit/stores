<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_state` as `states`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('state_id')->autoIncrement()->primary();
            $table->string('state', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
