<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2 acceptance: role-by-role login on the consolidated users table,
 * account-status gate, gradual hash upgrade, Q&A reset.
 */
class Phase2AuthTest extends TestCase
{
    private static bool $pipelineReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$pipelineReady) {
            return;
        }

        if (! Schema::hasTable('users')) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            $this->artisan('legacy:stage-import', ['--tables' => 'tbl_user,tbl_opr,tbl_roles,tbl_viewer,tblyears,tbl_parameters,tbl_classification,tbl_stores']);
            $this->artisan('legacy:migrate-data', ['--tables' => 'tbl_user,tbl_opr,tbl_roles,tbl_viewer,tblyears,tbl_parameters,tbl_classification,tbl_stores']);
        }

        self::$pipelineReady = true;
    }

    private function userForRole(string $role): User
    {
        $user = User::query()->where('role', $role)->first();
        if ($user === null) {
            $this->fail("No migrated user with role {$role}");
        }

        return $user;
    }

    /** @test */
    public function guests_are_redirected_to_login(): void
    {
        $this->get('/viewer')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');
    }

    /** @test */
    public function each_role_logs_into_its_own_dashboard(): void
    {
        // Staged passwords are unrecoverable hashes, so give one user per role
        // a known credential (restored afterwards — workers may run test
        // methods in any order, so mutations must never leak).
        foreach (['admin', 'operator', 'eindent', 'viewer'] as $role) {
            $user = $this->userForRole($role);
            $original = ['password' => $user->password, 'status' => $user->status];

            try {
                $user->update(['password' => 'demo123', 'status' => 'Active']);

                $response = $this->post('/login', [
                    'login' => $user->login,
                    'password' => 'demo123',
                ]);

                $response->assertRedirect(route($user->homeRoute()));

                // viewer.home deliberately forwards to the reports index
                // (legacy indexview parity), so follow one hop if given.
                $dashboard = $this->get(route($user->homeRoute()));
                if ($dashboard->isRedirect()) {
                    $dashboard = $this->get($dashboard->headers->get('Location'));
                }
                $dashboard->assertOk();

                $this->post('/logout');
            } finally {
                // Raw update bypassing casts — the migrated hash (bcrypt cost
                // 12) cannot pass the 'hashed' cast's configuration check
                // under the suite's BCRYPT_ROUNDS=4.
                DB::table('users')->where('id', $user->getKey())->update($original);
            }
        }
    }

    /** @test */
    public function suspended_accounts_cannot_log_in(): void
    {
        $user = User::query()->where('role', 'viewer')->firstOrFail();
        $original = ['password' => $user->password, 'status' => $user->status];

        try {
            $user->update(['status' => 'Suspend', 'password' => 'demo123']);

            $this->post('/login', ['login' => $user->login, 'password' => 'demo123'])
                ->assertSessionHasErrors('login');

            $this->assertGuest();
        } finally {
            // Raw update: the migrated hash (bcrypt cost 12) cannot pass the
            // 'hashed' cast's configuration check under the suite's
            // BCRYPT_ROUNDS=4, so bypass Eloquent casts when restoring.
            DB::table('users')->where('id', $user->getKey())->update($original);
        }
    }

    /** @test */
    public function wrong_password_is_rejected(): void
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();
        $original = $user->password;

        try {
            $user->update(['password' => 'correct-horse']);

            $this->post('/login', ['login' => $user->login, 'password' => 'wrong'])
                ->assertSessionHasErrors('login');

            $this->assertGuest();
        } finally {
            // Raw update bypassing casts — see note above.
            DB::table('users')->where('id', $user->getKey())->update(['password' => $original]);
        }
    }

    /** @test */
    public function legacy_plaintext_password_is_upgraded_to_bcrypt(): void
    {
        $user = User::query()->where('role', 'operator')->firstOrFail();
        $original = $user->password;

        try {
            $user->forceFill(['password' => 'plainpass'])->save();

            $this->post('/login', ['login' => $user->login, 'password' => 'plainpass'])
                ->assertRedirect();

            $fresh = $user->fresh();
            $this->assertTrue(Hash::check('plainpass', $fresh->password), 'password was not re-hashed');
            $this->assertStringStartsWith('$2y$', $fresh->password);
        } finally {
            // Raw update bypassing casts — see note above.
            DB::table('users')->where('id', $user->getKey())->update(['password' => $original]);
        }
    }

    /** @test */
    public function question_answer_reset_sets_new_password(): void
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();
        $original = ['password' => $user->password, 'question' => $user->question, 'answer' => $user->answer];

        try {
            $user->forceFill(['question' => 'Favourite colour?', 'answer' => 'teal', 'password' => 'oldpass'])->save();

            $this->post('/forgot-password', ['login' => $user->login, 'answer' => 'Teal'])
                ->assertRedirect(route('password.reset'));

            $this->get(route('password.reset'))->assertOk();

            $this->post('/reset-password', [
                'password' => 'brand-new-pass',
                'password_confirmation' => 'brand-new-pass',
            ])->assertRedirect(route('login'));

            $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        } finally {
            // Raw update: the migrated hash (bcrypt cost 12) cannot pass the
            // 'hashed' cast's configuration check under the suite's
            // BCRYPT_ROUNDS=4, so bypass Eloquent casts when restoring.
            DB::table('users')->where('id', $user->getKey())->update($original);
        }
    }

    private function loginWorked(User $user, string $password): bool
    {
        // Verify against the migrated hash (plaintext fallback included).
        return Hash::check($password, $user->password)
            || hash_equals($user->password, $password);
    }
}
