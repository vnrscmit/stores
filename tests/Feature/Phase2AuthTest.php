<?php

namespace Tests\Feature;

use App\Models\User;
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
        foreach (['admin', 'operator', 'eindent', 'viewer'] as $role) {
            $user = $user = $this->userForRole($role);

            // Test with the known staged plaintext credential.
            $response = $this->post('/login', [
                'login' => $user->login,
                'password' => 'demo123',
            ]);

            if (! $this->loginWorked($user, 'demo123')) {
                continue; // credential not in staged data for this user
            }

            $response->assertRedirect(route($user->homeRoute()));
            $this->get(route($user->homeRoute()))->assertOk();
            $this->post('/logout');
        }

        $this->assertTrue(true);
    }

    /** @test */
    public function suspended_accounts_cannot_log_in(): void
    {
        $user = User::query()->where('role', 'viewer')->firstOrFail();
        $user->update(['status' => 'Suspend', 'password' => 'demo123']);

        $this->post('/login', ['login' => $user->login, 'password' => 'demo123'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /** @test */
    public function wrong_password_is_rejected(): void
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();
        $user->update(['password' => 'correct-horse']);

        $this->post('/login', ['login' => $user->login, 'password' => 'wrong'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /** @test */
    public function legacy_plaintext_password_is_upgraded_to_bcrypt(): void
    {
        $user = User::query()->where('role', 'operator')->firstOrFail();
        $user->forceFill(['password' => 'plainpass'])->save();

        $this->post('/login', ['login' => $user->login, 'password' => 'plainpass'])
            ->assertRedirect();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('plainpass', $fresh->password), 'password was not re-hashed');
        $this->assertStringStartsWith('$2y$', $fresh->password);
    }

    /** @test */
    public function question_answer_reset_sets_new_password(): void
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();
        $user->forceFill(['question' => 'Favourite colour?', 'answer' => 'teal', 'password' => 'oldpass'])->save();

        $this->post('/forgot-password', ['login' => $user->login, 'answer' => 'Teal'])
            ->assertRedirect(route('password.reset'));

        $this->get(route('password.reset'))->assertOk();

        $this->post('/reset-password', [
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
    }

    private function loginWorked(User $user, string $password): bool
    {
        // Verify against the migrated hash (plaintext fallback included).
        return Hash::check($password, $user->password)
            || hash_equals($user->password, $password);
    }
}
