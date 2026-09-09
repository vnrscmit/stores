<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_ecaptive` as `external_captives`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_captives', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('cid')->unsigned()->autoIncrement()->primary();
            $table->integer('tid')->nullable();
            $table->date('date')->nullable();
            $table->string('pname', 100)->nullable();
            $table->string('address', 100)->nullable();
            $table->string('ccr', 50)->nullable();
            $table->string('cc', 50)->nullable();
            $table->date('ccrdate')->nullable();
            $table->string('mode', 50)->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('items_id')->nullable();
            $table->integer('qty')->nullable();
            $table->index(['tid'], 'ix_external_captives_1');
            $table->index(['classification_id'], 'ix_external_captives_2');
            $table->index(['items_id'], 'ix_external_captives_3');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_captives');
    }
};
