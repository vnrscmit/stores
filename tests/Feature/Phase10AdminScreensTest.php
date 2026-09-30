<?php

namespace Tests\Feature;

use App\Console\Commands\LegacyStageImport;
use App\Models\AuditLog;
use App\Models\FinancialYear;
use App\Models\User;
use App\Support\FiscalYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 10 slice 4 acceptance: the admin dashboard placeholders —
 * Users & Roles (the listing side of legacy add_operator.php /
 * add_viewer.php, Suspend blocking login) and Year Setting (the
 * tblyears state machine of current_year.php + closeyear.php).
 *
 * Phase 11 slice 1 extends the suite with the create/edit side of
 * the legacy account screens (add_operator.php / add_viewer.php /
 * add_indentrole.php / edit_*.php / adminprofile.php): the legacy
 * vocabulary (role, Active/Suspend, the five fixed security
 * questions), the per-role code series, the duplicate checks and
 * the non-case-sensitive security answers.
 *
 * Semantics under test:
 *  - users listing (roles/status vocabulary) + the Suspend/Activate
 *    toggle (admins and the signed-in account protected);
 *  - activation: chosen year -> flg 2 / status 'a', previously open
 *    years -> flg 1 (current_year.php), closed years refused;
 *  - closing: active -> flg 0 / status 'c', successor yearsid+1 ->
 *    flg 1 / status 'a' (closeyear.php), missing successor refused;
 *  - session's FY resolution flushed after every switch;
 *  - admin-only gate.
 *
 * FY-SAFETY: the year scenarios restore the migrated 22-23 row in
 * finally blocks so the shared FY never drifts for other suites; rows
 * created by the suite use TEST-prefixed names and are swept.
 */
class Phase10AdminScreensTest extends TestCase
{
    private static bool $pipelineReady = false;

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

    private function admin(): User
    {
        $user = User::query()->where('role', 'admin')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    private function sweep(): void
    {
        FinancialYear::query()->where('year_name', 'like', 'TEST%')->delete();
        User::query()->where('login', 'like', 'TEST-%')->delete();
        AuditLog::query()->where('module', 'admin.years')->delete();
        AuditLog::query()->where('module', 'admin.users')->delete();
    }

    public function test_users_screen_requires_admin(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));

        $operator = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($operator);

        $this->get(route('admin.users.index'))
            ->assertRedirect(route('operator.home'))
            ->assertSessionHas('warning');
    }

    public function test_users_listing_renders_all_roles(): void
    {
        $this->admin();

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Users &amp; Roles', false)
            ->assertSee('admin123');
    }

    public function test_suspend_blocks_login_and_activate_restores_it(): void
    {
        $this->admin();

        // A suite-created operator: a unique login keeps the login
        // throttle bucket isolated and no migrated row is mutated.
        $operator = User::query()->create([
            'login' => 'TEST-OP-'.uniqid(),
            'password' => 'demo123',
            'role' => 'operator',
            'status' => 'Active',
        ]);

        try {
            // Suspend (the login contract requires an Active account).
            $this->post(route('admin.users.toggle', $operator))->assertRedirect();
            $this->assertSame('Suspend', $operator->fresh()->status);

            // The login screen is guest-only — drop the admin session first.
            $this->post('/logout');

            $this->post('/login', ['login' => $operator->login, 'password' => 'demo123'])
                ->assertSessionHasErrors('login');
            $this->assertGuest();

            // Reactivate as admin, then the login succeeds.
            $this->admin();
            $this->post(route('admin.users.toggle', $operator))->assertRedirect();
            $this->assertSame('Active', $operator->fresh()->status);

            $this->post('/logout');
            $this->post('/login', ['login' => $operator->login, 'password' => 'demo123'])
                ->assertRedirect(route('operator.home'));
            $this->assertAuthenticatedAs($operator);
        } finally {
            $this->post('/logout');
            $operator->delete();
        }
    }

    public function test_admin_and_self_are_protected_from_the_toggle(): void
    {
        $admin = $this->admin();

        // Self-protection (the controller aborts with 422).
        $this->post(route('admin.users.toggle', $admin))->assertStatus(422);
        $this->assertSame('Active', $admin->fresh()->status);

        // Admin-protection (a second admin row, swept).
        $otherAdmin = User::query()->create([
            'login' => 'TEST-QR-ADMIN-'.uniqid(),
            'password' => 'x',
            'role' => 'admin',
            'status' => 'Active',
        ]);

        $this->post(route('admin.users.toggle', $otherAdmin))->assertStatus(422);
        $this->assertSame('Active', $otherAdmin->fresh()->status);

        $otherAdmin->delete();
    }

    public function test_year_screen_renders_with_active_row(): void
    {
        $this->admin();

        $this->get(route('admin.years.index'))
            ->assertOk()
            ->assertSee('Year Setting')
            ->assertSee(FiscalYear::name());
    }

    public function test_activate_switches_the_current_year(): void
    {
        $this->admin();

        $active = FiscalYear::resolve();

        $target = FinancialYear::query()
            ->where('years_status', '!=', 'c')
            ->where('yearsid', '!=', $active->yearsid)
            ->firstOrFail();

        try {
            $this->post(route('admin.years.activate', ['yearsid' => $target->yearsid]))->assertRedirect();

            // Chosen year: flg 2 + status 'a' (current_year.php).
            $target->refresh();
            $this->assertSame(2, (int) $target->years_flg);
            $this->assertSame('a', (string) $target->years_status);

            // The session's cached resolution was flushed — FY now
            // resolves to the CHOSEN year (flg 2).
            $this->assertSame($target->yearsid, FiscalYear::resolve()->yearsid);
            $this->assertSame(2, (int) FiscalYear::resolve()->years_flg);
            $this->assertSame((string) $target->ycode, (string) FiscalYear::yearcode());

            // The demoted year keeps its documents but loses active status.
            $this->assertSame('u', (string) $active->refresh()->years_status);
        } finally {
            // Restore the migrated baseline: 22-23 active again.
            FinancialYear::query()->where('ycode', '22-23')->update(['years_flg' => 1, 'years_status' => 'a']);
            FinancialYear::query()->where('yearsid', $target->yearsid)->update(['years_flg' => 0, 'years_status' => 'u']);
            FiscalYear::invalidateForTesting();
        }
    }

    public function test_closed_year_cannot_be_reactivated(): void
    {
        $this->admin();

        $closed = FinancialYear::query()->create([
            'year_name' => 'TESTCLOSED'.uniqid(),
            'years' => 'TEST_CLOSED',
            'years_flg' => 0,
            'years_status' => 'c',
            'ycode' => 'TC',
            'year1' => 2001,
            'year2' => 2002,
        ]);

        try {
            $this->post(route('admin.years.activate', ['yearsid' => $closed->yearsid]))
                ->assertStatus(422);

            // The active year is untouched.
            $this->assertSame('22-23', (string) FiscalYear::yearcode());
        } finally {
            $closed->delete();
            FiscalYear::invalidateForTesting();
        }
    }

    public function test_close_moves_the_flag_and_status_to_the_successor(): void
    {
        $this->admin();

        $active = FiscalYear::resolve();
        $successor = FinancialYear::query()->where('yearsid', $active->yearsid + 1)->first();

        try {
            if ($successor === null) {
                // No successor row in the shared DB: the controller must
                // refuse the close.
                $this->post(route('admin.years.close'))->assertStatus(422);
                $this->assertSame('a', (string) $active->refresh()->years_status);
            } else {
                $this->post(route('admin.years.close'))->assertRedirect();

                // Closing year: flg 0 + status 'c'; successor yearsid+1:
                // flg 1 + status 'a' (closeyear.php).
                $this->assertSame(0, (int) $active->refresh()->years_flg);
                $this->assertSame('c', (string) $active->years_status);
                $this->assertSame(1, (int) $successor->refresh()->years_flg);
                $this->assertSame('a', (string) $successor->years_status);
            }
        } finally {
            // Restore the migrated baseline rows.
            FinancialYear::query()->where('ycode', '22-23')->update(['years_flg' => 1, 'years_status' => 'a']);
            FinancialYear::query()->where('ycode', '23-24')->update(['years_flg' => 0, 'years_status' => 'u']);
            FiscalYear::invalidateForTesting();
            $this->sweep();
        }
    }

    public function test_year_actions_require_admin(): void
    {
        $operator = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($operator);

        $this->get(route('admin.years.index'))->assertRedirect(route('operator.home'));
        $this->post(route('admin.years.activate', ['yearsid' => 1]))->assertRedirect(route('operator.home'));
        $this->post(route('admin.years.close'))->assertRedirect(route('operator.home'));
    }

    public function test_create_screen_renders_legacy_vocabulary(): void
    {
        $this->admin();

        $this->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('New account')
            ->assertSee('What is the name of your first school?')
            ->assertSee('non-case-sensitive');
    }

    public function test_store_creates_account_with_role_code_and_hashed_secret(): void
    {
        $admin = $this->admin();

        $before = User::query()->where('role', 'operator')->get()
            ->map(fn (User $u) => (int) preg_replace('/\D/', '', (string) $u->code))->max();

        try {
            $this->post(route('admin.users.store'), [
                'name' => 'TEST Name',
                'login' => $login = 'TEST-CREATE-'.uniqid(),
                'password' => 'secret1',
                'email' => $login.'@example.com',
                'role' => 'operator',
                'status' => 'Active',
                'question' => "What is your mother's maiden name?",
                'answer' => '  Maiden  ',
            ])->assertRedirect(route('admin.users.index'));

            $user = User::query()->where('login', $login)->firstOrFail();
            $this->assertSame('TEST Name', $user->name);
            $this->assertSame('Active', $user->status);
            $this->assertSame("What is your mother's maiden name?", $user->question);

            // Bare-number series continued per role (data truth), stored
            // as legacy's display code OP{n}.
            $this->assertSame('OP'.($before + 1), (string) $user->code);

            // Password bcrypt ('hashed' cast); the answer bcrypt of the
            // lowercased trim and verifies non-case-sensitively.
            $this->assertNotSame('secret1', $user->password);
            $this->assertTrue(Hash::check('secret1', $user->password));
            $this->assertNotSame('maiden', $user->answer);
            $this->assertTrue(Hash::check('maiden', $user->answer));

            // Audit row for the create.
            $this->assertDatabaseHas('audit_logs', [
                'module' => 'admin.users', 'action' => 'create',
                'record_type' => 'users', 'record_id' => $user->getKey(),
                'user_id' => $admin->getKey(),
            ]);

            $user->delete();
        } finally {
            User::query()->where('login', 'like', 'TEST-CREATE-%')->delete();
        }
    }

    public function test_store_rejects_duplicate_login_email_and_bad_role(): void
    {
        $this->admin();

        $existing = User::query()->where('role', 'operator')->firstOrFail();

        // Duplicate login (legacy's "Duplicate not allowed.").
        $this->from(route('admin.users.create'))
            ->post(route('admin.users.store'), [
                'name' => 'TEST Dup',
                'login' => $existing->login,
                'password' => 'secret1',
                'email' => 'TEST-DUP-'.uniqid().'@example.com',
                'role' => 'operator',
                'status' => 'Active',
            ])
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors('login');

        // Duplicate e-mail.
        $this->post(route('admin.users.store'), [
            'name' => 'TEST Dup',
            'login' => 'TEST-DUP-'.uniqid(),
            'password' => 'secret1',
            'email' => $existing->email,
            'role' => 'operator',
            'status' => 'Active',
        ])->assertSessionHasErrors('email');

        // Role outside the legacy vocabulary.
        $this->post(route('admin.users.store'), [
            'name' => 'TEST Dup',
            'login' => 'TEST-DUP-'.uniqid(),
            'password' => 'secret1',
            'email' => 'TEST-DUP-'.uniqid().'@example.com',
            'role' => 'superadmin',
            'status' => 'Active',
        ])->assertSessionHasErrors('role');

        $this->assertSame(0, User::query()->where('name', 'TEST Dup')->count());
    }

    public function test_store_requires_question_and_answer_together(): void
    {
        $this->admin();

        // A question without an answer stores neither (the forgot-
        // password flow requires both to be set).
        $this->post(route('admin.users.store'), [
            'name' => 'TEST QA',
            'login' => 'TEST-QA-'.uniqid(),
            'password' => 'secret1',
            'email' => 'TEST-QA-'.uniqid().'@example.com',
            'role' => 'viewer',
            'status' => 'Active',
            'question' => 'What is your nick name?',
        ])->assertSessionHasErrors('answer');

        $this->assertSame(0, User::query()->where('name', 'TEST QA')->count());
    }

    public function test_edit_updates_profile_password_and_qa(): void
    {
        $this->admin();

        $user = User::query()->create([
            'login' => 'TEST-EDIT-'.uniqid(),
            'password' => 'oldpass1',
            'role' => 'operator',
            'name' => 'TEST Edit',
            'email' => 'TEST-EDIT-'.uniqid().'@example.com',
            'status' => 'Active',
            'code' => 'OPTEST',
        ]);

        $oldHash = $user->password;

        try {
            $this->put(route('admin.users.update', $user), [
                'login' => $user->login,
                'name' => 'TEST Edit 2',
                'email' => $user->email,
                'password' => '', // blank keeps the current password
                'status' => 'Suspend',
                'role' => $user->role,
                'question' => 'Which is your favourite vegetable?',
                'answer' => '  Baingan ',
            ])->assertRedirect(route('admin.users.index'));

            $user->refresh();
            $this->assertSame('TEST Edit 2', $user->name);
            $this->assertSame('Suspend', $user->status);
            $this->assertSame($oldHash, $user->password); // untouched
            $this->assertSame('Which is your favourite vegetable?', $user->question);
            // bcrypt stores the lowercased trim; legacy's non-case-
            // sensitivity lives in the reset contract (adminprofile.php
            // advertised answers as "Non-Case Sensitive").
            $this->assertTrue(Hash::check('baingan', $user->answer));

            // The reset flow is guest-only — drop the admin session.
            $this->post('/logout');

            $this->post(route('password.verify'), [
                'login' => $user->login,
                'answer' => 'BAINGAN',
            ])->assertRedirect(route('password.reset'));

            // Migrated rows keep legacy plaintext answers; the contract
            // stays case-insensitive on them (strcasecmp parity).
            $user->answer = ' Verbatim ';
            $user->save();
            $this->post(route('password.verify'), [
                'login' => $user->login,
                'answer' => 'vErBaTiM',
            ])->assertRedirect(route('password.reset'));

            // The login ID cannot drift to a duplicate; the role is fixed.
            // (Re-authenticate: the reset-flow visit logged the admin out.)
            $this->admin();
            $this->put(route('admin.users.update', $user), [
                'login' => $user->login,
                'name' => $user->name,
                'email' => $user->email,
                'status' => 'Active',
                'role' => 'viewer',
            ])->assertSessionHasErrors('role');
            $this->assertSame('operator', $user->refresh()->role);
        } finally {
            $user->delete();
        }
    }

    public function test_admin_accounts_are_not_editable(): void
    {
        $this->admin();

        $admin = User::query()->where('role', 'admin')->firstOrFail();

        $this->get(route('admin.users.edit', $admin))->assertStatus(422);

        $this->put(route('admin.users.update', $admin), [
            'login' => $admin->login,
            'name' => 'TEST Hack',
            // The migrated data shares admin@vnrseeds.com across two admin
            // rows (legacy never enforced e-mail uniqueness); a fresh unique
            // address keeps the unique-rule out of the way so the request
            // exercises the admin-guard abort this test targets.
            'email' => 'TEST-unique-admin@vnrseeds.com',
            'status' => 'Suspend',
            'role' => 'admin',
        ])->assertStatus(422);

        $this->assertNotSame('TEST Hack', $admin->refresh()->name);
    }

    public function test_account_actions_require_admin(): void
    {
        $operator = User::query()->where('role', 'operator')->firstOrFail();
        $this->actingAs($operator);

        $target = User::query()->where('role', 'operator')->whereKeyNot($operator->getKey())->firstOrFail();

        $this->get(route('admin.users.create'))->assertRedirect(route('operator.home'));
        $this->post(route('admin.users.store'), ['name' => 'X'])->assertRedirect(route('operator.home'));
        $this->get(route('admin.users.edit', $target))->assertRedirect(route('operator.home'));
        $this->put(route('admin.users.update', $target), ['name' => 'X'])->assertRedirect(route('operator.home'));
    }

    protected function tearDown(): void
    {
        $this->sweep();
        FiscalYear::invalidateForTesting();

        parent::tearDown();
    }
}
