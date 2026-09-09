<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Authorization gates mirroring the legacy four-role model.
        Gate::define('manage-masters', fn ($user) => $user->role === 'admin');
        Gate::define('administrate', fn ($user) => $user->role === 'admin');
        Gate::define('post-transactions', fn ($user) => in_array($user->role, ['operator', 'admin'], true));
        Gate::define('raise-indents', fn ($user) => in_array($user->role, ['eindent', 'admin'], true));
        Gate::define('view-reports', fn ($user) => in_array($user->role, ['viewer', 'operator', 'admin'], true));

        // Prevent any user from listing other users (IDOR hardening).
        Gate::define('viewAny', fn ($user) => $user->role === 'admin');
    }
}
