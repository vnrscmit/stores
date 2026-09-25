<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The QR code subsystem tables (Phase 10 slice 3).
 *
 * The legacy subsystem never went live: Transaction/generate_qr_codes.php
 * wrote tbl_qr_codes (whose CREATE TABLE is nowhere in the codebase — the
 * column set here is reconstructed verbatim from its INSERTs) and
 * utility/setup_qrcode_db.php created tbl_item_qrcodes + tbl_qr_scan_log
 * at runtime (schema preserved verbatim); none of them exist in the live
 * legacy database. Names are legacy with the tbl_ prefix dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            // Reconstructed from save_qr_codes.php / save_qr_temp.php INSERTs.
            $table->increments('id');
            $table->integer('arrival_id')->default(0);       // 0 = draft (save_qr_temp.php)
            $table->integer('arrsub_id')->default(0);        // 0 = draft
            $table->integer('classification_id');
            $table->integer('item_id');
            $table->string('qr_code_text');
            $table->decimal('weight', 10, 2)->default(0);    // entered per-code weight (kg)
            $table->dateTime('generated_date');
            $table->string('linked_status')->default('draft'); // draft | linked
            $table->string('created_by');
            $table->index(['arrival_id'], 'ix_qr_codes_1');
            $table->index(['item_id'], 'ix_qr_codes_2');
            $table->index(['classification_id'], 'ix_qr_codes_3');
            $table->unique(['qr_code_text'], 'ux_qr_codes_text');
        });

        // Verbatim utility/setup_qrcode_db.php DDL (tbl_qr_scan_log).
        Schema::create('qr_scan_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('id');
            $table->integer('qrcode_id');
            $table->timestamp('scan_time')->useCurrent();
            $table->string('action')->default('scan'); // scan | update_weight | discard | return
            $table->integer('operator_id')->nullable();
            $table->decimal('previous_weight', 10, 2)->nullable();
            $table->decimal('new_weight', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->index(['scan_time'], 'ix_qr_scan_logs_1');
            $table->index(['qrcode_id'], 'ix_qr_scan_logs_2');
            $table->index(['action'], 'ix_qr_scan_logs_3');
        });

        // Verbatim utility/setup_qrcode_db.php DDL (tbl_item_type_code).
        Schema::create('qr_item_types', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('type_id');
            $table->string('type_name', 50)->unique();
            $table->integer('type_code')->unique(); // 11=Roll, 12=Pouches, 13=Stickers
            $table->text('description')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('classifications', function (Blueprint $table) {
            $table->string('classification_type', 20)->nullable()->after('classification');
        });

        // Verbatim setup_qrcode_db.php step 6 (default item types).
        DB::table('qr_item_types')->insert([
            ['type_name' => 'Roll', 'type_code' => 11, 'description' => 'Rolled items'],
            ['type_name' => 'Pouches', 'type_code' => 12, 'description' => 'Pouch items'],
            ['type_name' => 'Stickers', 'type_code' => 13, 'description' => 'Sticker items'],
        ]);
    }

    public function down(): void
    {
        Schema::table('classifications', function (Blueprint $table) {
            $table->dropColumn('classification_type');
        });

        Schema::dropIfExists('qr_item_types');
        Schema::dropIfExists('qr_scan_logs');
        Schema::dropIfExists('qr_codes');
    }
};
