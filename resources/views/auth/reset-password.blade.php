@extends('layouts.app')

@section('title', 'Set new password')

@section('content')
<div style="max-width:460px;margin:2rem auto">
    <div class="card">
        <h2>Set a new password for {{ $login }}</h2>
        <p style="font-size:.85rem;color:var(--muted)">{{ $question }}</p>
        <form method="POST" action="{{ route('password.reset.store') }}">
            @csrf
            <label style="display:block;margin-bottom:.8rem">
                <span style="font-size:.8rem;color:var(--muted)">New password</span>
                <input type="password" name="password" minlength="6"
                       style="width:100%;padding:.5rem;border:1px solid var(--line);border-radius:.4rem">
            </label>
            <label style="display:block;margin-bottom:1rem">
                <span style="font-size:.8rem;color:var(--muted)">Confirm password</span>
                <input type="password" name="password_confirmation"
                       style="width:100%;padding:.5rem;border:1px solid var(--line);border-radius:.4rem">
            </label>
            <button class="btn" type="submit">Update password</button>
        </form>
    </div>
</div>
@endsection
