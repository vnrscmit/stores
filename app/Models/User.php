<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Consolidated auth user. Legacy auth spanned tbl_user (operator/admin),
 * tbl_opr (superseded operator table), tbl_roles (eindent) and tbl_viewer
 * (viewer) — legacy login checked tbl_user first, so its row wins on
 * duplicate logins. Passwords are bcrypt; legacy plaintext/old-hash users
 * are transparently re-hashed on first successful login (gradual migration).
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = ['login', 'password', 'role', 'status', 'party_id', 'remember_token'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed', // transparent bcrypt on every assignment
        ];
    }

    public function getAuthPassword()
    {
        return $this->password;
    }

    /** Legacy active check: only Active accounts may log in. */
    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /**
     * Named route of the role dashboard a user lands on after login
     * (legacy index1 / indexopr / indexindet / indexview parity).
     */
    public function homeRoute(): string
    {
        return match ($this->role) {
            'admin' => 'admin.home',
            'operator' => 'operator.home',
            'eindent' => 'eindent.home',
            'viewer' => 'viewer.home',
            default => 'login',
        };
    }
}
