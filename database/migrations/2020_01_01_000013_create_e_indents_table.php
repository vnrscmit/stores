<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_ieindent` as `e_indents`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_indents', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('tid')->unsigned()->autoIncrement()->primary();
            $table->integer('code1')->nullable();
            $table->date('tdate')->nullable();
            $table->integer('code')->nullable();
            $table->integer('id')->nullable();
            $table->tinyInteger('flg')->unsigned()->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->tinyInteger('tflg')->unsigned()->nullable();
            $table->text('remarks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_indents');
    }
};
