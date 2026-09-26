<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Users & Roles — the port of the legacy Masters "Operator"/"Viewer"
 * screens' listing side (add_operator.php / add_viewer.php manage one
 * user each; the port's users table stores all roles with the legacy
 * status vocabulary 'Active'/'Suspend').
 *
 * Creation/edition stays with the existing auth flows; this screen gives
 * the admin the oversight the dashboard card promised (role, login,
 * status, legacy identity) and the Suspend/Activate toggle — suspending
 * blocks login (legacy vocabulary preserved; the login contract requires
 * an Active account).
 */
class UserController extends Controller
{
    /** The users & roles listing. */
    public function index(): View
    {
        $users = User::query()
            ->orderBy('role')
            ->orderBy('login')
            ->get();

        return view('admin.users', [
            'users' => $users,
            'roles' => [
                'admin' => User::query()->where('role', 'admin')->count(),
                'operator' => User::query()->where('role', 'operator')->count(),
                'eindent' => User::query()->where('status', 'Active')->where('role', 'eindent')->count(),
                'viewer' => User::query()->where('role', 'viewer')->count(),
            ],
        ]);
    }

    /** Toggle Active/Suspend (suspend blocks login). */
    public function toggle(User $user): RedirectResponse
    {
        abort_unless($user->role !== 'admin', 422, 'Admin accounts cannot be suspended here.');
        abort_if($user->getKey() === auth()->id(), 422, 'You cannot suspend your own account.');

        $user->status = $user->status === 'Active' ? 'Suspend' : 'Active';
        $user->save();

        Audit::changed('admin.users', $user->status === 'Active' ? 'activate' : 'suspend', $user, ['status']);

        return back()->with('status',
            $user->status === 'Active' ? "Account {$user->login} activated." : "Account {$user->login} suspended.");
    }
}
