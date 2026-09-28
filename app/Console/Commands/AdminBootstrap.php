<?php

namespace App\Console\Commands;

use App\Http\Requests\Admin\UserRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Validation\Rules\Password;

/**
 * Admin bootstrap: create the first admin account or reset an existing
 * admin's password from the command line, so a freshly migrated
 * database is loggable-into without hand-written SQL (discovered during
 * browser verification: migrated password hashes cannot be logged into
 * because the legacy hashes/plaintext predate the port's bcrypt scheme
 * and only re-hash on a successful login — which the operator cannot
 * perform without a working password).
 *
 * Modes:
 *   php artisan admin:bootstrap                       (interactive prompts)
 *   php artisan admin:bootstrap --login=admin --password=secret
 *   php artisan admin:bootstrap --login=admin123      (reset that admin only)
 *
 * Passwords are set through the model's 'hashed' cast exactly like the
 * account screens (UserRequest's 6+ floor included); the event is
 * audit-logged under module admin.users with the CLI invoker recorded
 * as user_id NULL — the same "no authenticated session" shape the
 * import pipeline leaves.
 */
class AdminBootstrap extends Command
{
    protected $signature = 'admin:bootstrap
                            {--login= : Login of the admin account to create or reset}
                            {--password= : New password (prompted, hidden, when omitted)}
                            {--name= : Display name (creation only; defaults to the login)}
                            {--email= : E-mail address (creation only)}';

    protected $description = 'Create or reset an admin account so a freshly migrated database is loggable-into';

    public function handle(): int
    {
        $login = $this->option('login');

        if ($login === null) {
            if (! $this->input->isInteractive()) {
                $this->error('The --login option is required in non-interactive mode.');

                return self::FAILURE;
            }

            $login = $this->ask('Admin login');
        }

        $login = trim((string) $login);
        if ($login === '') {
            $this->error('The login cannot be empty.');

            return self::FAILURE;
        }

        $user = User::query()->where('login', $login)->first();

        if ($user !== null && $user->role !== 'admin') {
            $this->error("Login [{$login}] belongs to a {$user->role} account — only admin accounts are managed here.");

            return self::FAILURE;
        }

        $password = $this->option('password');

        if ($password === null) {
            if (! $this->input->isInteractive()) {
                $this->error('The --password option is required in non-interactive mode.');

                return self::FAILURE;
            }

            $password = $this->secret('New password (input hidden, min 6 characters)');
        }

        if (strlen((string) $password) < 6) {
            $this->error('The password must be at least 6 characters.');

            return self::FAILURE;
        }

        if ($user === null) {
            $user = $this->createAdmin($login, (string) $password);

            if ($user === null) {
                return self::FAILURE;
            }

            $this->info("Admin account [{$user->login}] created (id {$user->getKey()}, status {$user->status}).");
        } else {
            $admins = User::query()->where('role', 'admin')->count();
            if ($admins > 1) {
                $this->warn("Resetting admin [{$user->login}]; {$admins} admin accounts exist in total.");
            }

            $user->update(['password' => $password]); // 'hashed' cast

            $user->refresh();
            if ($user->status !== 'Active') {
                $previous = $user->status; // syncOriginal() after update would erase it
                $user->update(['status' => 'Active']);
                $this->warn("Account status was {$previous} — reactivated.");
            }

            // No password material in the audit snapshot (the account
            // screens exclude it from tracked attributes the same way).
            Audit::log('admin.users', 'password-reset', $user, null, ['login' => $user->login, 'via' => 'cli']);

            $this->info("Password of admin account [{$user->login}] (id {$user->getKey()}) has been reset.");
        }

        return self::SUCCESS;
    }

    /** First-account creation, mirroring Admin\UserController@store; null on validation failure. */
    private function createAdmin(string $login, string $password): ?User
    {
        $name = (string) ($this->option('name') ?? $login);
        $email = $this->option('email');

        if ($email === null && $this->input->isInteractive()) {
            $email = $this->ask('E-mail address (optional, blank to skip)');
        }

        if ((string) $email !== '') {
            $validated = validator(
                ['email' => $email, 'password' => $password],
                ['email' => ['string', 'email', 'max:100'], 'password' => [Password::min(6)]],
            );

            if ($validated->fails()) {
                $this->error('E-mail validation: '.$validated->errors()->first('email'));

                return null;
            }
        }

        $user = User::query()->create([
            'login' => $login,
            'password' => $password, // 'hashed' cast
            'role' => 'admin',
            'name' => $name,
            'email' => ((string) $email !== '') ? $email : null,
            'status' => 'Active',
            'code' => '0', // the migrated admin row carries code '0'; no legacy series for admins
        ]);

        Audit::changed('admin.users', 'create', $user, ['login', 'role', 'status', 'code']);

        $this->line('Security question left unset — the admin can set one via the account screens ('.UserRequest::SECURITY_QUESTIONS[0].' …).');

        return $user;
    }
}
