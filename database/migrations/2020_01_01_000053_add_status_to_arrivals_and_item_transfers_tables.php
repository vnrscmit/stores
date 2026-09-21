<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port extension for the arrivals-family lifecycle (Phase 9): `status`
 * mirrors the legacy flags (arrivals.arrtrflag, item_transfers.iitrflg) as
 * readable states (open|posted). The posting transactions flip both
 * together, so a post cannot run twice.
 *
 * arrivals covers all three arrival document types (Vendor, Stocktransfer,
 * Internalreturn) — one flag/column pair for the family. item_transfers is
 * the ITI/ITA document; legacy never set iitrflg (the commit block was
 * commented out), so migrated rows land on 'open' and only ported posts
 * mark them 'posted'.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = app(Builder::class);

        if ($schema->hasTable('arrivals') && ! $schema->hasColumn('arrivals', 'status')) {
            Schema::table('arrivals', function (Blueprint $table) {
                $table->string('status', 20)->nullable()->after('arrtrflag');
                $table->index('status', 'ix_arrivals_status');
            });

            DB::table('arrivals')->where('arrtrflag', 1)->update(['status' => 'posted']);
            DB::table('arrivals')->where(function ($q) {
                $q->where('arrtrflag', '!=', 1)->orWhereNull('arrtrflag');
            })->update(['status' => 'open']);
        }

        if ($schema->hasTable('item_transfers') && ! $schema->hasColumn('item_transfers', 'status')) {
            Schema::table('item_transfers', function (Blueprint $table) {
                $table->string('status', 20)->nullable()->after('iitrflg');
                $table->index('status', 'ix_item_transfers_status');
            });

            DB::table('item_transfers')->where('iitrflg', 1)->update(['status' => 'posted']);
            DB::table('item_transfers')->where(function ($q) {
                $q->where('iitrflg', '!=', 1)->orWhereNull('iitrflg');
            })->update(['status' => 'open']);
        }
    }

    public function down(): void
    {
        $schema = app(Builder::class);

        if ($schema->hasTable('arrivals') && $schema->hasColumn('arrivals', 'status')) {
            Schema::table('arrivals', function (Blueprint $table) {
                $table->dropIndex('ix_arrivals_status');
                $table->dropColumn('status');
            });
        }

        if ($schema->hasTable('item_transfers') && $schema->hasColumn('item_transfers', 'status')) {
            Schema::table('item_transfers', function (Blueprint $table) {
                $table->dropIndex('ix_item_transfers_status');
                $table->dropColumn('status');
            });
        }
    }
};
