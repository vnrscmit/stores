<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tblissue_sub` as `issue_items`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('issuesub_id')->unsigned()->autoIncrement()->primary();
            $table->integer('issue_id')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('item_id')->nullable();
            $table->integer('ups_indent')->unsigned()->nullable();
            $table->decimal('qty_indent', 10, 3)->nullable();
            $table->integer('noofbin_good')->nullable();
            $table->string('uom', 50)->nullable();
            $table->text('remarks')->nullable();
            $table->string('rettype', 100)->nullable();
            $table->index(['issue_id'], 'ix_issue_items_1');
            $table->index(['classification_id'], 'ix_issue_items_2');
            $table->index(['item_id'], 'ix_issue_items_3');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_items');
    }
};
