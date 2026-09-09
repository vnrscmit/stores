<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_ireturn` as `internal_returns`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_returns', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('rid')->unsigned()->autoIncrement()->primary();
            $table->integer('code')->nullable();
            $table->integer('date')->nullable();
            $table->string('rfs', 50)->nullable();
            $table->string('rbd', 50)->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('items_id')->nullable();
            $table->string('uom', 50)->nullable();
            $table->integer('ups')->nullable();
            $table->decimal('qty', 10, 3)->nullable();
            $table->integer('whid')->nullable();
            $table->integer('binid')->nullable();
            $table->integer('sid')->nullable();
            $table->index(['classification_id'], 'ix_internal_returns_1');
            $table->index(['items_id'], 'ix_internal_returns_2');
            $table->index(['whid'], 'ix_internal_returns_3');
            $table->index(['binid'], 'ix_internal_returns_4');
            $table->index(['sid'], 'ix_internal_returns_5');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_returns');
    }
};
