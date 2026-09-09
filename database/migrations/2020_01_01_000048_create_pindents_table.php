<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_pindents` as `pindents`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pindents', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id')->unsigned()->autoIncrement()->primary();
            $table->integer('pindent')->nullable();
            $table->date('issuedate')->nullable();
            $table->integer('iraised')->unsigned()->nullable();
            $table->integer('code')->nullable();
            $table->date('indentdate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pindents');
    }
};
