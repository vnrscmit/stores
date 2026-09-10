<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Central audit logger. Captures user, module, action, record identity,
 * before/after snapshots, and IP. Legacy had no audit trail (only
 * audit_trail_debug.php); this is additive and changes no legacy behaviour.
 */
class Audit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function log(string $module, string $action, ?Model $record = null, ?array $before = null, ?array $after = null): void
    {
        $user = Auth::user();

        AuditLog::create([
            'user_id' => $user?->getAuthIdentifier(),
            'user_login' => $user?->login,
            'module' => $module,
            'action' => $action,
            'record_type' => $record?->getMorphClass(),
            'record_id' => $record?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
        ]);
    }

    /**
     * Log a model change with automatic before/after snapshots of the
     * given attribute list (empty list = all changed attributes).
     *
     * @param  array<int, string>  $attributes
     */
    public static function changed(string $module, string $action, Model $record, array $attributes = []): void
    {
        // Freshly created models have an empty getChanges() diff — snapshot
        // the full attribute set as the "after" state instead.
        if ($record->wasRecentlyCreated) {
            self::log($module, $action, $record, null, $record->getAttributes());

            return;
        }

        $tracked = $attributes === []
            ? array_keys($record->getChanges())
            : $attributes;

        $before = [];
        $after = [];
        foreach ($tracked as $attr) {
            if (array_key_exists($attr, $record->getChanges()) || $attributes !== []) {
                $before[$attr] = $record->getOriginal($attr);
                $after[$attr] = $record->getAttribute($attr);
            }
        }

        self::log($module, $action, $record, $before, $after);
    }
}
