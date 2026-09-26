<x-masters.form action="{{ route('admin.users.update', $user) }}" :isEdit="true">
    <h2>Edit account — {{ $user->login }}</h2>
    <p class="muted">
        The login ID and role are fixed once created (legacy had no
        re-role screen). Leave the password blank to keep the current
        one; supply question + answer to set the security Q&A.
    </p>

    <div class="filters">
        <div>
            <label>Login ID</label>
            <input type="text" value="{{ $user->login }}" disabled>
            <input type="hidden" name="login" value="{{ $user->login }}">
        </div>
        <div>
            <label>Role</label>
            <input type="text" value="{{ $user->role }}" disabled>
            <input type="hidden" name="role" value="{{ $user->role }}">
        </div>
        <div>
            <label>Name *</label>
            <input type="text" name="name" value="{{ old('name', $user->name) }}" maxlength="100" required>
        </div>
        <div>
            <label>E-mail *</label>
            <input type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="100" required>
        </div>
        <div>
            <label>Password</label>
            <input type="password" name="password" maxlength="15">
            <p class="muted">Leave blank to keep the current password; at least 6 characters.</p>
        </div>
        <div>
            <label>Status *</label>
            <label style="font-weight:normal"><input type="radio" name="status" value="Active" @checked(old('status', $user->status) === 'Active')> Active</label>
            <label style="font-weight:normal"><input type="radio" name="status" value="Suspend" @checked(old('status', $user->status) === 'Suspend')> Suspend</label>
        </div>
        <div>
            <label>Security Question</label>
            <select name="question">
                <option value="">— keep current —</option>
                @foreach ($questions as $question)
                    <option value="{{ $question }}" @selected(old('question', $user->question) === $question)>{{ $question }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Security Answer (non-case-sensitive)</label>
            <input type="text" name="answer" value="{{ old('answer') }}" maxlength="50" placeholder="Leave blank to keep current">
        </div>
    </div>
</x-masters.form>
