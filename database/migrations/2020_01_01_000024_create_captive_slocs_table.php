<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_captive_sloc` as `captive_slocs`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captive_slocs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('issuesloc_id')->unsigned()->autoIncrement()->primary();
            $table->string('issue_type', 50)->nullable();
            $table->integer('issue_trid')->nullable();
            $table->integer('isue_id')->nullable();
            $table->integer('classification_id')->nullable();
            $table->integer('item_id')->nullable();
            $table->integer('whid')->nullable();
            $table->integer('binid')->nullable();
            $table->string('subbin', 100)->nullable();
            $table->decimal('qty_issue', 10, 3)->nullable();
            $table->integer('ups_issue')->nullable();
            $table->decimal('qty_balance', 10, 3)->nullable();
            $table->integer('ups_balance')->nullable();
            $table->string('issue_rowid', 100)->nullable();
            $table->integer('eid')->nullable();
            $table->index(['issue_trid'], 'ix_captive_slocs_1');
            $table->index(['isue_id'], 'ix_captive_slocs_2');
            $table->index(['classification_id'], 'ix_captive_slocs_3');
            $table->index(['item_id'], 'ix_captive_slocs_4');
            $table->index(['whid'], 'ix_captive_slocs_5');
            $table->index(['binid'], 'ix_captive_slocs_6');
            $table->index(['subbin'], 'ix_captive_slocs_7');
            $table->index(['eid'], 'ix_captive_slocs_8');
            $table->index(['issue_rowid'], 'ix_captive_slocs_9');
            $table->index('issue_trid');
            $table->index('isue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captive_slocs');
    }
};
