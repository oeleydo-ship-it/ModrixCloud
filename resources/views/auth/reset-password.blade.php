@extends('layouts.site', ['title' => 'Choose a new password', 'bodyClass' => 'marketplace-body auth-body'])

@section('content')
    <section class="auth-card">
        <span class="eyebrow">YOUR MARKETPLACE ACCOUNT</span>
        <h1>Choose a new password</h1>
        <form method="post" action="{{ url('/account/reset-password') }}" class="stack">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label>Email <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email"></label>
            <label>New password (12+ characters) <input type="password" name="password" required minlength="12" autocomplete="new-password" autofocus></label>
            <label>Confirm new password <input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
            <button type="submit">Save new password</button>
        </form>
        <p class="meta">Link expired? <a href="{{ url('/account/forgot-password') }}">Ask for a new one</a></p>
    </section>
@endsection
