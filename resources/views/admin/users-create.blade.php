<x-masters.form action="{{ route('admin.users.store') }}">
    <h2>New account</h2>
    <p class="muted">
        Legacy add_operator / add_viewer vocabulary: role, status and the
        adminprofile security question. The account code is assigned from
        the role's series (OP… / SRV… / EI…).
    </p>

    <div class="filters">
        <div>
            <label>Name *</label>
            <input type="text" name="name" value="{{ old('name') }}" maxlength="100" required>
        </div>
        <div>
            <label>Login ID *</label>
            <input type="text" name="login" value="{{ old('login') }}" maxlength="100" required>
        </div>
        <div>
            <label>Password *</label>
            <input type="password" name="password" maxlength="15" required>
            <p class="muted">At least 6 characters.</p>
        </div>
        <div>
            <label>E-mail *</label>
            <input type="email" name="email" value="{{ old('email') }}" maxlength="100" required>
        </div>
        <div>
            <label>Role *</label>
            <select name="role" required>
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected(old('role') === $role)>{{ $role }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Status *</label>
            <label style="font-weight:normal"><input type="radio" name="status" value="Active" @checked(old('status', 'Active') === 'Active')> Active</label>
            <label style="font-weight:normal"><input type="radio" name="status" value="Suspend" @checked(old('status') === 'Suspend')> Suspend</label>
        </div>
        <div>
            <label>Security Question</label>
            <select name="question">
                <option value="">— none —</option>
                @foreach ($questions as $question)
                    <option value="{{ $question }}" @selected(old('question') === $question)>{{ $question }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Security Answer (non-case-sensitive)</label>
            <input type="text" name="answer" value="{{ old('answer') }}" maxlength="50">
        </div>
    </div>
</x-masters.form>
