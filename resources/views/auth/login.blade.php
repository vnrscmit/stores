@extends('layouts.app')

@section('title', 'Login')

@section('content')
<div style="max-width:420px;margin:2rem auto">
    <div class="card">
        <h2>Sign in</h2>
        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <label style="display:block;margin-bottom:.8rem">
                <span style="font-size:.8rem;color:var(--muted)">Login ID</span>
                <input type="text" name="login" value="{{ old('login') }}" autofocus
                       style="width:100%;padding:.5rem;border:1px solid var(--line);border-radius:.4rem">
            </label>
            <label style="display:block;margin-bottom:1rem">
                <span style="font-size:.8rem;color:var(--muted)">Password</span>
                <input type="password" name="password"
                       style="width:100%;padding:.5rem;border:1px solid var(--line);border-radius:.4rem">
            </label>
            <label style="display:flex;gap:.4rem;align-items:center;margin-bottom:1rem;font-size:.85rem">
                <input type="checkbox" name="remember" value="1"> Remember me
            </label>
            <button class="btn" type="submit">Sign in</button>
            <a href="{{ route('password.question') }}" style="margin-left:1rem;font-size:.85rem">Forgot password?</a>
        </form>
    </div>
</div>
@endsection
