<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Users & Roles — the port of the legacy Masters "Operator"/"Viewer"
 * screens (add_operator.php / add_viewer.php / add_indentrole.php /
 * edit_*.php) and the listing side the Phase 10 dashboard card
 * promised. The port's users table stores all roles with the legacy
 * status vocabulary 'Active'/'Suspend'.
 *
 * Create/edit parity: name, login, password, e-mail, status + the
 * adminprofile.php security Q&A (five fixed questions, answers stored
 * bcrypt but verified non-case-sensitive by the forgot-password flow).
 * Legacy auto-numbered accounts per role table (OP… / SRV… / EI…);
 * the port assigns the next number of the role's series (data truth:
 * users.code carries the bare numbers 13…, 27…, 42…). Duplicate
 * name/login/email checks collapse into unique rules — legacy checked
 * both the role table and tbl_user before refusing with a single
 * "Duplicate not allowed." alert.
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
                'operator' => User::query()->where('status', 'Active')->where('role', 'operator')->count(),
                'eindent' => User::query()->where('status', 'Active')->where('role', 'eindent')->count(),
                'viewer' => User::query()->where('role', 'viewer')->count(),
            ],
        ]);
    }

    /** Legacy add_operator.php: OP{max+1}; add_viewer.php: SRV…; add_indentrole.php: EI…. */
    private function nextCode(string $role): string
    {
        $prefixes = ['operator' => 'OP', 'eindent' => 'EI', 'viewer' => 'SRV'];

        $max = User::query()
            ->where('role', $role)
            ->get(['code'])
            ->map(fn (User $row) => preg_match('/(\d+)\s*$/', (string) $row->code, $m) ? (int) $m[1] : 0)
            ->max();

        return $prefixes[$role].($max + 1);
    }

    public function create(): View
    {
        return view('admin.users-create', [
            'roles' => UserRequest::ROLES,
            'questions' => UserRequest::SECURITY_QUESTIONS,
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'login' => $data['login'],
            'password' => $data['password'], // 'hashed' cast (legacy stored plaintext)
            'role' => $data['role'],
            'name' => $data['name'],
            'email' => $data['email'],
            'status' => $data['status'],
            'code' => $this->nextCode($data['role']),
            'question' => $data['question'] ?? null,
            // Stored bcrypt of the lowercased trim (legacy answers were
            // non-case-sensitive; the forgot-password check keeps that).
            'answer' => ($data['question'] ?? null) !== null ? Hash::make(strtolower(trim($data['answer']))) : null,
        ]);

        Audit::changed('admin.users', 'create', $user, ['login', 'role', 'status', 'code', 'question']);

        return redirect()
            ->route('admin.users.index')
            ->with('status', "Account {$user->login} created.");
    }

    public function edit(User $user): View
    {
        abort_unless($user->role !== 'admin', 422, 'Admin accounts are managed by the system.');

        return view('admin.users-edit', [
            'user' => $user,
            'questions' => UserRequest::SECURITY_QUESTIONS,
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        abort_unless($user->role !== 'admin', 422, 'Admin accounts are managed by the system.');

        $data = $request->validated();

        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'status' => $data['status'],
        ];

        if (($data['password'] ?? '') !== '') {
            $attributes['password'] = $data['password']; // 'hashed' cast
        }

        if (($data['question'] ?? null) !== null && ($data['answer'] ?? '') !== '') {
            $attributes['question'] = $data['question'];
            // bcrypt of the lowercased trim — non-case-sensitive parity.
            $attributes['answer'] = Hash::make(strtolower(trim($data['answer'])));
        }

        $user->fill($attributes)->save();

        Audit::changed('admin.users', 'update', $user, ['name', 'email', 'status', 'question']);

        return redirect()
            ->route('admin.users.index')
            ->with('status', "Account {$user->login} updated.");
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
