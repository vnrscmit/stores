<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\FiscalYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    /**
     * Legacy validatelogin.php parity:
     *  - find by login name (tbl_user.loginid / tbl_opr.login / ...),
     *  - account status must be Active,
     *  - role decides the landing dashboard.
     * Gradual hash migration: bcrypt first, legacy plaintext accepted once
     * and transparently re-hashed on success.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Please enter your login ID.',
            'password.required' => 'Please enter your password.',
        ]);

        $user = User::query()
            ->where('login', $credentials['login'])
            ->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'login' => 'Invalid Login ID & Password. Please contact Administrator or try again.',
            ]);
        }

        $ok = Hash::check($credentials['password'], $user->password);

        // Gradual migration path: legacy plaintext values that survived the
        // data migration verify directly and are re-hashed immediately.
        if (! $ok && hash_equals($user->password, $credentials['password'])) {
            $ok = true;
            $user->password = $credentials['password']; // 'hashed' cast re-hashes
            $user->save();
        }

        if (! $ok) {
            throw ValidationException::withMessages([
                'login' => 'Invalid Login ID & Password. Please contact Administrator or try again.',
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'login' => 'This account is suspended. Please contact the Administrator.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        FiscalYear::flush();

        return redirect()->route($user->homeRoute());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        FiscalYear::flush();

        return redirect()->route('login');
    }
}
