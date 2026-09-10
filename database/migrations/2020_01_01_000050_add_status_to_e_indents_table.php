<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port extension over the legacy tbl_ieindent flags: an approval gate
 * between final submit (tflg=1) and operator issuance (flg=1).
 * Existing rows default to 'approved' (open for issue), matching the
 * 43,635 legacy rows already flowing through the issue module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e_indents', function (Blueprint $table) {
            $table->string('status', 20)->default('approved')->after('tflg');
            $table->index(['status', 'yearcode']);
            $table->index(['id', 'status', 'yearcode']);
        });
    }

    public function down(): void
    {
        Schema::table('e_indents', function (Blueprint $table) {
            $table->dropIndex(['id', 'status', 'yearcode']);
            $table->dropIndex(['status', 'yearcode']);
            $table->dropColumn('status');
        });
    }
};
