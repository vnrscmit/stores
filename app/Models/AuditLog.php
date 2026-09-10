<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Application audit trail (Phase 19 slice). Written by App\Support\Audit on
 * every master CRUD action, login, and (later) transaction posting.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false; // created_at handled by DB default

    protected $fillable = [
        'user_id', 'user_login', 'module', 'action',
        'record_type', 'record_id', 'before', 'after', 'ip', 'created_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];
}
