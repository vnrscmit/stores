<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Hard-gate a route group to the given role(s). Users of a different role
     * land on their own module home — legacy index*.php parity — rather than
     * receiving a bare 403.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user, 401);

        if (in_array($user->role, $roles, true)) {
            return $next($request);
        }

        // Cross-role access: bounce to the user's own module home.
        $home = match ($user->role) {
            'admin' => route('admin.home'),
            'operator' => route('operator.home'),
            'eindent' => route('eindent.home'),
            'viewer' => route('viewer.home'),
            default => url('/login'),
        };

        return redirect($home)->with('warning', 'You are not authorized to access that module.');
    }
}
