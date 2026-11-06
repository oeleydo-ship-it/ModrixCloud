@extends('install.layout')

@section('content')
    @if ($reset ?? false)
        <h1>Admin access saved</h1>
        <p><strong>{{ $email }}</strong> is an administrator with the new password. Any other sessions of this account were signed out.</p>
        <p class="notice">Now remove <code>MARKETPLACE_REOPEN_INSTALLER</code> from the site's environment and redeploy, so this page closes again.</p>
    @else
        <h1>Setup complete</h1>
        <p>The marketplace is installed and this setup page is now closed.</p>
        <p class="meta">Next: sign in, then add your Stripe keys, email settings and marketplace name under Settings. Set up the cron job from the deployment guide so emails go out.</p>
    @endif
    <p><a class="button" href="{{ url('/admin/login') }}">Sign in to the admin</a></p>
@endsection
