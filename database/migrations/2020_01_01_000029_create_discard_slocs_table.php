<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_discard_sloc` as `discard_slocs`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discard_slocs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('discardsloc_id')->unsigned()->autoIncrement()->primary();
            $table->string('discard_type', 50)->nullable();
            $table->integer('discard_trid')->nullable();
            $table->integer('discard_id')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('item_id')->nullable();
            $table->integer('whid')->nullable();
            $table->integer('binid')->nullable();
            $table->integer('subbin')->nullable();
            $table->decimal('qty_discard', 10, 3)->nullable();
            $table->integer('ups_discard')->nullable();
            $table->decimal('qty_balance', 10, 3)->nullable();
            $table->integer('ups_balance')->nullable();
            $table->integer('discard_rowid')->nullable();
            $table->integer('eid')->nullable();
            $table->index(['discard_trid'], 'ix_discard_slocs_1');
            $table->index(['discard_id'], 'ix_discard_slocs_2');
            $table->index(['classification_id'], 'ix_discard_slocs_3');
            $table->index(['item_id'], 'ix_discard_slocs_4');
            $table->index(['whid'], 'ix_discard_slocs_5');
            $table->index(['binid'], 'ix_discard_slocs_6');
            $table->index(['subbin'], 'ix_discard_slocs_7');
            $table->index(['discard_rowid'], 'ix_discard_slocs_8');
            $table->index(['eid'], 'ix_discard_slocs_9');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discard_slocs');
    }
};
