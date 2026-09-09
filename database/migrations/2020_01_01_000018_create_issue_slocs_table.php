<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tblissue_sloc` as `issue_slocs`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_slocs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('issuesloc_id')->unsigned()->autoIncrement()->primary();
            $table->string('issue_type', 100)->nullable();
            $table->integer('issue_tr_id')->unsigned()->nullable();
            $table->integer('issue_id')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('item_id')->nullable();
            $table->integer('whid')->nullable();
            $table->integer('binid')->nullable();
            $table->integer('subbin')->nullable();
            $table->decimal('qty_issue', 10, 3)->nullable();
            $table->integer('ups_issue')->nullable();
            $table->decimal('qty_balance', 10, 3)->nullable();
            $table->integer('ups_balance')->nullable();
            $table->integer('issue_rowid')->unsigned()->nullable();
            $table->integer('eid')->unsigned()->nullable();
            $table->index(['issue_type'], 'ix_issue_slocs_1');
            $table->index(['issue_tr_id'], 'ix_issue_slocs_2');
            $table->index(['issue_id'], 'ix_issue_slocs_3');
            $table->index(['classification_id'], 'ix_issue_slocs_4');
            $table->index(['item_id'], 'ix_issue_slocs_5');
            $table->index(['whid'], 'ix_issue_slocs_6');
            $table->index(['binid'], 'ix_issue_slocs_7');
            $table->index(['subbin'], 'ix_issue_slocs_8');
            $table->index(['eid'], 'ix_issue_slocs_9');
            $table->index('issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_slocs');
    }
};
