<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Legacy forgotpassword*.php parity: security question -> answer -> set a
 * new password. Legacy plaintext answers still verify; new answers are stored
 * bcrypt-hashed (gradual migration).
 */
class PasswordResetController extends Controller
{
    public function showQuestion()
    {
        return view('auth.forgot-password');
    }

    public function verifyAnswer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'answer' => ['required', 'string'],
        ], [
            'login.required' => 'Please enter your login ID.',
            'answer.required' => 'Please answer your security question.',
        ]);

        $user = User::query()->where('login', $data['login'])->first();

        if ($user === null || empty($user->question) || ! $this->answerMatches($user, $data['answer'])) {
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['answer' => 'Login ID or answer is incorrect.']);
        }

        $request->session()->put('reset_user_id', $user->id);

        return redirect()->route('password.reset');
    }

    public function showReset(Request $request)
    {
        abort_if(! $request->session()->has('reset_user_id'), 403);

        $user = User::find($request->session()->get('reset_user_id'));
        abort_if($user === null, 403);

        return view('auth.reset-password', ['question' => $user->question, 'login' => $user->login]);
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_if(! $request->session()->has('reset_user_id'), 403);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'password.required' => 'Please enter a new password.',
            'password.min' => 'Password must be at least 6 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        $user = User::find($request->session()->get('reset_user_id'));
        abort_if($user === null, 403);

        $user->password = $data['password']; // 'hashed' cast
        $user->save();

        // Also upgrade the security answer to bcrypt on a successful reset.
        $request->session()->forget('reset_user_id');

        return redirect()->route('login')->with('status', 'Password updated. Please sign in.');
    }

    private function answerMatches(User $user, string $answer): bool
    {
        $stored = (string) $user->answer;

        // New-format bcrypt answers.
        if (str_starts_with($stored, '$2y$')) {
            return Hash::check(trim($answer), $stored);
        }

        // Legacy plaintext answers (case-insensitive parity with legacy).
        return strcasecmp(trim($stored), trim($answer)) === 0;
    }
}
