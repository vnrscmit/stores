<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 11 hardening: `php artisan admin:bootstrap` — the documented
 * recovery tool so a freshly migrated database is loggable-into without
 * hand-written SQL (the browser-verification discovery: migrated
 * hashes predate the port's bcrypt scheme and only re-hash on a
 * successful login, which the operator cannot perform without a
 * working password).
 *
 * Semantics under test:
 *  - reset mode: an existing admin's password is set through the same
 *    'hashed' cast as the account screens and the account ACTUALLY logs
 *    in through POST /login afterwards (the end-to-end acceptance);
 *  - create mode: an unknown --login creates the first admin (role
 *    admin, status Active, login works end-to-end);
 *  - non-interactive (-n) mode requires both options and mutates
 *    nothing when they are missing;
 *  - non-admin logins are refused; short passwords are refused;
 *  - a suspended admin is reactivated by the reset;
 *  - audit rows under module admin.users, CLI-shaped (user_id NULL),
 *    and none for failed runs.
 */
class Phase11AdminBootstrapTest extends TestCase
{
    private static bool $pipelineReady = false;

    /** Throwaway admin login used by the create-mode tests. */
    private const BOOTSTRAP_LOGIN = 'zz_bootstrap_admin';

    /** Throwaway admin's original attributes, for the sweep. */
    private ?array $bootstrapOriginal = null;

    /** The migrated admin row's original attributes, for the sweep. */
    private ?array $migratedOriginal = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$pipelineReady) {
            if (! Schema::hasTable('users')) {
                $this->artisan('migrate:fresh', ['--force' => true]);
                $this->artisan('legacy:stage-import', ['--limit' => (string) LegacyStageImport::TEST_SUBSET_LIMIT]);
                $this->artisan('legacy:migrate-data');
                $this->artisan('legacy:integrity-fix', ['--strategy' => 'placeholder']);
                $this->artisan('legacy:integrity-fix', ['--strategy' => 'null']);
                $this->artisan('legacy:add-constraints');
            }

            // Apply pending migrations added after this database was built.
            $this->artisan('migrate', ['--force' => true]);

            self::$pipelineReady = true;
        }
    }

    protected function tearDown(): void
    {
        $this->sweep();
        $this->bootstrapOriginal = null;
        $this->migratedOriginal = null;

        parent::tearDown();
    }

    private function migratedAdmin(): User
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();

        return $user;
    }

    private function sweep(): void
    {
        AuditLog::query()->where('module', 'admin.users')->delete();

        // Raw restore: migrated hashes cannot pass the 'hashed' cast's
        // configuration check under BCRYPT_ROUNDS=4 (see Phase2AuthTest).
        if ($this->migratedOriginal !== null) {
            DB::table('users')->where('id', $this->migratedOriginal['id'])->update($this->migratedOriginal);
            $this->migratedOriginal = null;
        }

        // The create-mode row: remove it entirely (its hash was made by
        // this suite, so a plain Eloquent delete is safe here).
        User::query()->where('login', self::BOOTSTRAP_LOGIN)->delete();
    }

    public function test_reset_makes_a_migrated_admin_loggable_in(): void
    {
        $admin = $this->migratedAdmin();
        $this->migratedOriginal = $admin->getAttributes();

        $password = 'bootstrap-pw1';

        $this->artisan('admin:bootstrap', [
            '-n' => true,
            '--login' => $admin->login,
            '--password' => $password,
        ])->assertExitCode(0);

        // The login must work end-to-end through POST /login.
        $this->post('/login', ['login' => $admin->login, 'password' => $password])
            ->assertRedirect(route('admin.home'));
        $this->assertAuthenticatedAs($admin->fresh());

        // One audit row, CLI-shaped (no authenticated session involved).
        $row = AuditLog::query()->where('module', 'admin.users')->firstOrFail();
        $this->assertSame('password-reset', $row->action);
        $this->assertSame('users', $row->record_type);
        $this->assertSame($admin->getKey(), (int) $row->record_id);
        $this->assertNull($row->user_id);
    }

    public function test_create_makes_a_fresh_db_loggable_in(): void
    {
        $this->artisan('admin:bootstrap', [
            '-n' => true,
            '--login' => self::BOOTSTRAP_LOGIN,
            '--password' => 'bootstrap-pw2',
            '--name' => 'Bootstrap Admin',
            '--email' => 'bootstrap@vnrseeds.com',
        ])->assertExitCode(0);

        $user = User::query()->where('login', self::BOOTSTRAP_LOGIN)->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertSame('Active', $user->status);
        $this->assertSame('Bootstrap Admin', $user->name);
        $this->assertSame('bootstrap@vnrseeds.com', $user->email);

        $this->post('/login', ['login' => self::BOOTSTRAP_LOGIN, 'password' => 'bootstrap-pw2'])
            ->assertRedirect(route('admin.home'));

        $row = AuditLog::query()->where('module', 'admin.users')->firstOrFail();
        $this->assertSame('create', $row->action);
        $this->assertSame('users', $row->record_type);
        $this->assertNull($row->user_id);
    }

    public function test_non_interactive_mode_requires_both_options(): void
    {
        $admin = $this->migratedAdmin();
        $this->migratedOriginal = $admin->getAttributes();

        $this->artisan('admin:bootstrap', ['-n' => true])->assertExitCode(1);

        $this->artisan('admin:bootstrap', ['-n' => true, '--login' => $admin->login])
            ->assertExitCode(1);

        $this->artisan('admin:bootstrap', ['-n' => true, '--password' => 'whatever1'])
            ->assertExitCode(1);

        // Nothing was touched: same hash, no audit row.
        $this->assertSame(
            $this->migratedOriginal['password'],
            $admin->fresh()->password,
        );
        $this->assertSame(0, AuditLog::query()->where('module', 'admin.users')->count());
    }

    public function test_non_admin_login_is_refused(): void
    {
        $operator = User::query()->where('role', 'operator')->firstOrFail();
        $original = $operator->getAttributes();

        try {
            $this->artisan('admin:bootstrap', [
                '-n' => true,
                '--login' => $operator->login,
                '--password' => 'bootstrap-pw3',
            ])->assertExitCode(1);

            $fresh = $operator->fresh();
            $this->assertSame($original['password'], $fresh->password);
            $this->assertSame('operator', $fresh->role);
            $this->assertSame(0, AuditLog::query()->where('module', 'admin.users')->count());
        } finally {
            DB::table('users')->where('id', $operator->getKey())->update($original);
        }
    }

    public function test_short_password_is_refused(): void
    {
        $this->artisan('admin:bootstrap', [
            '-n' => true,
            '--login' => self::BOOTSTRAP_LOGIN,
            '--password' => 'abc',
        ])->assertExitCode(1);

        $this->assertNull(User::query()->where('login', self::BOOTSTRAP_LOGIN)->first());
        $this->assertSame(0, AuditLog::query()->where('module', 'admin.users')->count());
    }

    public function test_suspended_admin_is_reactivated_and_logs_in(): void
    {
        // Throwaway admin so the migrated admin login is untouched.
        $this->artisan('admin:bootstrap', [
            '-n' => true,
            '--login' => self::BOOTSTRAP_LOGIN,
            '--password' => 'bootstrap-pw4',
        ])->assertExitCode(0);

        $user = User::query()->where('login', self::BOOTSTRAP_LOGIN)->firstOrFail();
        $this->bootstrapOriginal = $user->getAttributes();

        DB::table('users')->where('id', $user->getKey())->update(['status' => 'Suspend']);
        $user->refresh();
        $this->assertFalse($user->isActive());

        $this->artisan('admin:bootstrap', [
            '-n' => true,
            '--login' => self::BOOTSTRAP_LOGIN,
            '--password' => 'bootstrap-pw5',
        ])->assertExitCode(0);

        $user->refresh();
        $this->assertTrue($user->isActive());

        $this->post('/login', ['login' => self::BOOTSTRAP_LOGIN, 'password' => 'bootstrap-pw5'])
            ->assertRedirect(route('admin.home'));
    }

    public function test_reset_of_a_second_admin_warns_but_succeeds(): void
    {
        // Create a throwaway second admin so the migrated admin is one
        // of two, then reset the migrated admin's password.
        $this->artisan('admin:bootstrap', [
            '-n' => true,
            '--login' => self::BOOTSTRAP_LOGIN,
            '--password' => 'bootstrap-pw6',
        ])->assertExitCode(0);

        $admin = $this->migratedAdmin();
        $this->migratedOriginal = $admin->getAttributes();

        $this->artisan('admin:bootstrap', [
            '-n' => true,
            '--login' => $admin->login,
            '--password' => 'bootstrap-pw7',
        ])->assertExitCode(0);

        $this->post('/login', ['login' => $admin->login, 'password' => 'bootstrap-pw7'])
            ->assertRedirect(route('admin.home'));
    }
}
