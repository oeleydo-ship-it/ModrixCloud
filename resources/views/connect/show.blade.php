@extends('layouts.site', ['title' => 'Connect your store'])

@section('content')
    <section class="panel connect-panel">
        <span class="eyebrow">MODRIX CLOUD CONNECTOR</span>
        <h1>Connect {{ $request['store_name'] }}?</h1>
        <p>
            <strong>{{ $request['domain'] }}</strong> wants to connect to your account
            <strong>{{ auth()->user()->email }}</strong>.
        </p>
        <p class="meta">Once connected, the store can:</p>
        <ul class="meta">
            <li>show which themes and extensions you own,</li>
            <li>get the license keys of your free and purchased packages, and activate and update them on this store.</li>
        </ul>
        <p class="meta">It cannot buy anything, change your account or see your payment details. You can disconnect it at any time from your account page or from the store.</p>

        @if ($refusal)
            <p class="error" role="alert">{{ $refusal }}</p>
        @endif
        @error('store')<p class="error" role="alert">{{ $message }}</p>@enderror
        <div class="inline-form" @if ($refusal) hidden @endif>
            <form method="post" action="{{ url('/connect') }}">
                @csrf
                @foreach ($request as $field => $value)
                    <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                @endforeach
                <button type="submit">Connect store</button>
            </form>
            <form method="post" action="{{ url('/connect/deny') }}">
                @csrf
                @foreach ($request as $field => $value)
                    <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                @endforeach
                <button class="secondary" type="submit">Cancel</button>
            </form>
        </div>
        <p class="meta">You will return to {{ parse_url($request['return_url'], PHP_URL_HOST) }}.</p>
    </section>
@endsection
