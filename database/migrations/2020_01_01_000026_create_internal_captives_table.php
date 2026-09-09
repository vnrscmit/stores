<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_icaptive` as `internal_captives`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_captives', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('id')->unsigned()->autoIncrement()->primary();
            $table->integer('code')->nullable();
            $table->date('date')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('items_id')->nullable();
            $table->string('uom', 50)->nullable();
            $table->string('ups', 50)->nullable();
            $table->decimal('qty', 10, 3)->nullable();
            $table->index(['classification_id'], 'ix_internal_captives_1');
            $table->index(['items_id'], 'ix_internal_captives_2');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_captives');
    }
};
