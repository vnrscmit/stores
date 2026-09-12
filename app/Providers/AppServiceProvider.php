<?php

namespace App\Providers;

use App\Models\Bin;
use App\Models\Captive;
use App\Models\CaptiveItem;
use App\Models\Classification;
use App\Models\EIndent;
use App\Models\EIndentItem;
use App\Models\Issue;
use App\Models\IssueItem;
use App\Models\IssueType;
use App\Models\Item;
use App\Models\Party;
use App\Models\SubBin;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        // Readable audit record types: table-name aliases for the models the
        // audit trail tracks (unmapped classes keep their FQCN).
        Relation::morphMap([
            'users' => User::class,
            'warehouses' => Warehouse::class,
            'bins' => Bin::class,
            'sub_bins' => SubBin::class,
            'classifications' => Classification::class,
            'items' => Item::class,
            'parties' => Party::class,
            'e_indents' => EIndent::class,
            'e_indent_items' => EIndentItem::class,
            'issues' => Issue::class,
            'issue_items' => IssueItem::class,
            'issue_types' => IssueType::class,
            'captives' => Captive::class,
            'captive_items' => CaptiveItem::class,
            'captive_slocs' => CaptiveSloc::class,
            'issue_slocs' => IssueSloc::class,
        ]);
    }
}
