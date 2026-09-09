<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tblissue` as `issues`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('issue_id')->unsigned()->autoIncrement()->primary();
            $table->string('issue_type', 100)->nullable();
            $table->integer('issue_code')->nullable();
            $table->integer('iss_code')->unsigned()->nullable();
            $table->date('issue_date')->nullable();
            $table->date('indent_date')->nullable();
            $table->string('dcrefno', 50)->nullable();
            $table->string('strefno', 50)->nullable();
            $table->date('strdate')->nullable();
            $table->string('rettype', 100)->nullable();
            $table->integer('party_id')->nullable();
            $table->string('tmode', 100)->nullable();
            $table->string('trans_name', 100)->nullable();
            $table->string('trans_lorryrepno', 50)->nullable();
            $table->string('trans_vehno', 50)->nullable();
            $table->string('trans_paymode', 50)->nullable();
            $table->string('courier_name', 100)->nullable();
            $table->string('docket_no', 50)->nullable();
            $table->string('pname_byhand', 250)->nullable();
            $table->text('remarks')->nullable();
            $table->integer('issuetrflag')->unsigned()->nullable();
            $table->string('issue_role', 50)->nullable();
            $table->integer('ncode')->unsigned()->nullable();
            $table->string('yearcode', 50)->nullable();
            $table->string('rettyp', 50)->nullable();
            $table->index(['issue_type'], 'ix_issues_1');
            $table->index(['issue_code'], 'ix_issues_2');
            $table->index(['iss_code'], 'ix_issues_3');
            $table->index(['issue_date'], 'ix_issues_4');
            $table->index(['indent_date'], 'ix_issues_5');
            $table->index(['party_id'], 'ix_issues_6');
            $table->index(['issuetrflag'], 'ix_issues_7');
            $table->index(['ncode'], 'ix_issues_8');
            $table->index(['rettyp'], 'ix_issues_9');
            $table->index('party_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
