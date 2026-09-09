<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_partymaser` as `parties`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('p_id')->unsigned()->autoIncrement()->primary();
            $table->string('classification', 100)->nullable();
            $table->text('business_name')->nullable();
            $table->string('contact', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 200)->default('India');
            $table->integer('pin')->nullable();
            $table->bigInteger('mob')->nullable();
            $table->integer('std')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('tin', 100)->nullable();
            $table->string('cst', 100)->nullable();
            $table->string('pan', 100)->nullable();
            $table->string('product', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
