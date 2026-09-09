<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recreates legacy table `tbl_party_ldg` as `party_ledgers`.
 * Columns and primary keys preserved verbatim from storesd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_ledgers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->integer('pldg_id')->unsigned()->autoIncrement()->primary();
            $table->string('pldg_trtype', 50)->nullable();
            $table->string('pldg_trsubtype', 50)->nullable();
            $table->integer('pldg_trid')->default('0');
            $table->date('pldg_trdate');
            $table->integer('pldg_trpartyid')->default('0');
            $table->integer('pldg_trclassid')->default('0');
            $table->integer('pldg_tritemid')->default('0');
            $table->integer('pldg_trdcups')->default('0');
            $table->decimal('pldg_trdcqty', 10, 3)->default('0.000');
            $table->integer('pldg_trgoodups')->default('0');
            $table->decimal('pldg_trgoodqty', 10, 3)->default('0.000');
            $table->integer('pldg_trdamageups')->default('0');
            $table->decimal('pldg_trdamageqty', 10, 3)->default('0.000');
            $table->decimal('pldg_trexqty', 10, 3)->default('0.000');
            $table->decimal('pldg_trshqty', 10, 3)->default('0.000');
            $table->integer('pldg_trbalups')->default('0');
            $table->decimal('pldg_trbalqty', 10, 3)->default('0.000');
            $table->string('yearcode', 10)->nullable();
            $table->index(['pldg_trid'], 'ix_party_ledgers_1');
            $table->index(['pldg_trpartyid'], 'ix_party_ledgers_2');
            $table->index(['pldg_trclassid'], 'ix_party_ledgers_3');
            $table->index(['pldg_tritemid'], 'ix_party_ledgers_4');
            $table->index('pldg_trid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_ledgers');
    }
};
