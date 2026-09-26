@extends('layouts.app')

@section('title', 'Users & Roles')

@section('content')
<div class="card">
    <h2>Users &amp; Roles</h2>

    <p class="muted">
        Accounts by role (legacy add_operator / add_viewer vocabulary). A
        suspended account cannot log in; admin accounts are managed out of
        this screen.
    </p>

    <p>
        <a class="btn" href="{{ route('admin.users.create') }}">New account</a>
    </p>

    <table class="data">
        <thead>
        <tr>
            <th>Login</th>
            <th>Name</th>
            <th>Role</th>
            <th>Status</th>
            <th>Legacy id</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($users as $user)
            <tr>
                <td><strong>{{ $user->login }}</strong></td>
                <td>{{ $user->name }}</td>
                <td>{{ $user->role }}</td>
                <td>{{ $user->status }}</td>
                <td>{{ $user->legacy_id ?? '—' }} {{ $user->legacy_source ? "({$user->legacy_source})" : '' }}</td>
                <td>
                    @if ($user->role !== 'admin' && $user->getKey() !== auth()->id())
                        <a class="btn secondary" href="{{ route('admin.users.edit', $user) }}">Edit</a>
                        <form method="POST" action="{{ route('admin.users.toggle', $user) }}" style="display:inline">
                            @csrf
                            <button class="btn secondary" type="submit">
                                {{ $user->status === 'Active' ? 'Suspend' : 'Activate' }}
                            </button>
                        </form>
                    @elseif ($user->role === 'admin')
                        <span class="muted">admin</span>
                    @else
                        <a class="btn secondary" href="{{ route('admin.users.edit', $user) }}">Edit</a>
                        <span class="muted">you</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
