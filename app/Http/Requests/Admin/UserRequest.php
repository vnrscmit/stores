<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The create/edit account form, porting the legacy add_operator.php /
 * add_viewer.php / add_indentrole.php field set and their duplicate
 * checks: legacy compared name, login and email against BOTH the role
 * table (tbl_opr / tbl_viewer / tbl_roles) and tbl_user before the
 * insert and refused with a single "Duplicate not allowed." alert —
 * the port's consolidated users table collapses that into one unique
 * check per column, scoped to the row being edited (legacy edit
 * screens used the same id/scode exclusion).
 *
 * Vocabulary preserved verbatim: role vocabulary, status 'Active' /
 * 'Suspend', the five fixed security questions of adminprofile.php
 * (answers are non-case-sensitive both in legacy storage and in the
 * port's forgot-password check), and the 6+ character password floor
 * of the port's own reset contract (legacy stored plaintext).
 */
class UserRequest extends FormRequest
{
    /** The legacy adminprofile.php security questions, in order. */
    public const SECURITY_QUESTIONS = [
        'What is the name of your first school?',
        'What is your nick name?',
        'Who is your favourite movie star?',
        "What is your mother's maiden name?",
        'Which is your favourite vegetable?',
    ];

    /** The legacy role vocabulary. */
    public const ROLES = ['operator', 'eindent', 'viewer'];

    public function authorize(): bool
    {
        return true; // the route group already gates on role:admin
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            // Legacy edit screens required every field (edit_operator.php
            // "All fields are required."); legacy add screens validated
            // client-side only — the port validates server-side.
            'name' => ['required', 'string', 'max:100'],
            'login' => ['required', 'string', 'max:100', Rule::unique('users', 'login')->ignore($user?->getKey())],
            // Optional on edit: an empty password keeps the current one
            // (legacy edit screens re-submitted the stored plaintext).
            'password' => array_filter([
                $this->isMethod('POST') ? 'required' : 'nullable',
                'string',
                Password::min(6),
            ]),
            'email' => ['required', 'string', 'email', 'max:100', Rule::unique('users', 'email')->ignore($user?->getKey())],
            'status' => ['required', Rule::in(['Active', 'Suspend'])],
            // The role is fixed once created (legacy had no re-role
            // screen): an existing row may only round-trip its own role.
            'role' => ['required', $user?->role
                ? Rule::in([$user->role])
                : Rule::in(self::ROLES)],
            'question' => ['nullable', 'string', Rule::in(self::SECURITY_QUESTIONS)],
            // Optional pair: a question without an answer stores neither
            // (the forgot-password flow requires both to be set).
            'answer' => ['nullable', 'string', 'max:50', 'required_with:question'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.unique' => 'Duplicate not allowed.',
            'email.unique' => 'Duplicate not allowed.',
            'email.email' => 'Please enter a valid e-mail address.',
            'answer.required_with' => 'Type the security answer for the selected question.',
        ];
    }
}
