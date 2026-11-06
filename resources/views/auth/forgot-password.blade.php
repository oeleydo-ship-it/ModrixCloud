@extends('layouts.site', ['title' => 'Reset your password', 'bodyClass' => 'marketplace-body auth-body'])

@section('content')
    <section class="auth-card">
        <span class="eyebrow">YOUR MARKETPLACE ACCOUNT</span>
        <h1>Reset your password</h1>
        <p class="meta">Enter the email you signed up with. We'll email you a link to choose a new password.</p>
        <form method="post" action="{{ url('/account/forgot-password') }}" class="stack">
            @csrf
            <label>Email <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus></label>
            <button type="submit">Email me a reset link</button>
        </form>
        <p class="meta">Remembered it? <a href="{{ url('/account/login') }}">Log in</a></p>
    </section>
@endsection
