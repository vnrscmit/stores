<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port extension for the issue/CC lifecycle: `status` mirrors the legacy
 * flags (issues.issuetrflag, captives.ccflg) as readable states
 * (open|posted — see EIssueStatus). The posting transactions flip both
 * together, so a post cannot run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('issues') && ! Schema::hasColumn('issues', 'status')) {
            Schema::table('issues', function (Blueprint $table) {
                $table->string('status', 20)->nullable()->after('issuetrflag');
                $table->index('status', 'ix_issues_status');
            });

            DB::table('issues')->where('issuetrflag', 1)->update(['status' => 'posted']);
            DB::table('issues')->where(function ($q) {
                $q->where('issuetrflag', '!=', 1)->orWhereNull('issuetrflag');
            })->update(['status' => 'open']);
        }

        if (Schema::hasTable('captives') && ! Schema::hasColumn('captives', 'status')) {
            Schema::table('captives', function (Blueprint $table) {
                $table->string('status', 20)->nullable()->after('ccflg');
                $table->index('status', 'ix_captives_status');
            });

            DB::table('captives')->where('ccflg', 1)->update(['status' => 'posted']);
            DB::table('captives')->where(function ($q) {
                $q->where('ccflg', '!=', 1)->orWhereNull('ccflg');
            })->update(['status' => 'open']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('issues') && Schema::hasColumn('issues', 'status')) {
            Schema::table('issues', function (Blueprint $table) {
                $table->dropIndex('ix_issues_status');
                $table->dropColumn('status');
            });
        }

        if (Schema::hasTable('captives') && Schema::hasColumn('captives', 'status')) {
            Schema::table('captives', function (Blueprint $table) {
                $table->dropIndex('ix_captives_status');
                $table->dropColumn('status');
            });
        }
    }
};
