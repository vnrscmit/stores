<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel system tables: consolidated users (from tbl_user/tbl_opr/tbl_roles/
 * tbl_viewer), plus cache/jobs/session plumbing for queue + session drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('login')->unique();
            $table->string('password');
            $table->string('role', 20); // admin | operator | eindent | viewer
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('Active');
            $table->string('code')->nullable();          // legacy tbl_opr.code / tbl_roles.code / tbl_viewer.vcode
            $table->unsignedBigInteger('legacy_id')->nullable(); // source row id in the legacy auth table
            $table->string('legacy_source', 20)->nullable();     // tbl_user | tbl_opr | tbl_roles | tbl_viewer
            $table->string('question')->nullable();      // legacy Q&A password reset
            $table->text('answer')->nullable();          // plaintext legacy / bcrypt new
            $table->unsignedInteger('uid')->nullable();  // legacy tbl_user.uid
            $table->string('scode', 10)->nullable();     // legacy tbl_user.scode
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        // Audit log (app-level; legacy had audit_trail_debug.php only).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_login', 100)->nullable();
            $table->string('module', 100);
            $table->string('action', 50);
            $table->string('record_type', 100)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['record_type', 'record_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['module', 'action']);
        });

        // Per-year document counters replacing MAX+1 race-prone numbering.
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 30);
            $table->string('yearcode', 20);
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();

            $table->unique(['doc_type', 'yearcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
