<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The plant code column the legacy code referenced but its live schema
 * lacked (the same situation as the QR subsystem's classification_type):
 * add_company.php / edit_company.php read and wrote tbl_parameters
 * .plantcode and fed it into the QR generator's {plant} part — but the
 * live legacy DB has no such column, so the legacy screens' plant-code
 * edits silently vanished. The port adds the column (varchar(20), the
 * legacy maxlength), backfills it from the staged legacy table when
 * that still carries the column (older dumps), and lets the company
 * profile editor manage it. QrSerial reads it as the {plant} prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('plantcode', 20)->nullable()->after('plant');
        });

        $staged = Schema::hasTable('legacy_tbl_parameters')
            && Schema::hasColumn('legacy_tbl_parameters', 'plantcode');

        if ($staged) {
            DB::statement(
                'UPDATE company_settings cs '
                .'JOIN legacy_tbl_parameters lp ON lp.id = cs.id '
                .'SET cs.plantcode = lp.plantcode '
                .'WHERE lp.plantcode IS NOT NULL AND lp.plantcode <> \'\''
            );
        }
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('plantcode');
        });
    }
};
