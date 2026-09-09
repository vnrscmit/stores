<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_sloc` as `slocs`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slocs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('slid')->unsigned()->autoIncrement()->primary();
            $table->integer('code')->nullable();
            $table->date('issuedate')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('items_id')->nullable();
            $table->string('uom', 20)->nullable();
            $table->integer('scode')->unsigned()->nullable();
            $table->tinyInteger('supflg')->unsigned()->nullable();
            $table->integer('noofbinsg')->unsigned()->nullable();
            $table->integer('noofbinsd')->unsigned()->nullable();
            $table->string('itmtype', 20)->nullable();
            $table->string('yearcode', 20)->nullable();
            $table->string('surole', 50)->nullable();
            $table->index(['classification_id'], 'ix_slocs_1');
            $table->index(['items_id'], 'ix_slocs_2');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slocs');
    }
};
