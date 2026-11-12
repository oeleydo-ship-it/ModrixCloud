@extends('layouts.site', ['title' => 'Check your email', 'bodyClass' => 'marketplace-body auth-body'])

@section('content')
    <section class="auth-card">
        <span class="eyebrow">ONE MORE STEP</span>
        <h1>Check your email</h1>
        <p>We sent a link to <strong>{{ $email }}</strong>. Open it to confirm your address, then you can get licenses, buy packages and publish your own.</p>
        <p class="meta">The link works for 60 minutes. Nothing arrived? Check your spam folder or send a new link.</p>
        <form method="post" action="{{ url('/account/verify-email/resend') }}" class="stack">
            @csrf
            <button type="submit">Resend the link</button>
        </form>
        <form method="post" action="{{ url('/account/logout') }}" class="verify-signout">
            @csrf
            <button class="link-button" type="submit">Sign out</button>
        </form>
    </section>
@endsection
