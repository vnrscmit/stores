@extends('layouts.app')

@section('title', 'Forgot password')

@section('content')
<div style="max-width:460px;margin:2rem auto">
    <div class="card">
        <h2>Password reset — verify your identity</h2>
        <form method="POST" action="{{ route('password.verify') }}">
            @csrf
            <label style="display:block;margin-bottom:.8rem">
                <span style="font-size:.8rem;color:var(--muted)">Login ID</span>
                <input type="text" name="login" value="{{ old('login') }}"
                       style="width:100%;padding:.5rem;border:1px solid var(--line);border-radius:.4rem">
            </label>
            <label style="display:block;margin-bottom:1rem">
                <span style="font-size:.8rem;color:var(--muted)">Security answer</span>
                <input type="text" name="answer"
                       style="width:100%;padding:.5rem;border:1px solid var(--line);border-radius:.4rem">
                <span style="font-size:.75rem;color:var(--muted)">Answer the security question on record for this account.</span>
            </label>
            <button class="btn" type="submit">Continue</button>
            <a href="{{ route('login') }}" style="margin-left:1rem;font-size:.85rem">Back to login</a>
        </form>
    </div>
</div>
@endsection
