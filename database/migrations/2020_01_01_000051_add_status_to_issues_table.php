<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port extension for the e-Issue lifecycle: `status` mirrors the legacy
 * issuetrflag as a readable state (open|posted). See EIssueStatus. The
 * posting transaction flips both together, so it cannot run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->index()->after('issuetrflag');
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
