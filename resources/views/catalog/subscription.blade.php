@extends('layouts.site', ['title' => 'Monthly subscription'])

@section('content')
    <nav class="detail-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ url('/') }}">Marketplace</a><span>/</span><span>Subscription</span>
    </nav>
    <section class="panel subscription-plan">
        <span class="eyebrow">MONTHLY SUBSCRIPTION</span>
        <h1>{{ $plan['name'] }}</h1>
        @if ($available)
            <p class="detail-price">{{ $priceLabel }}</p>
            <p>Get a license key for every package below while you are subscribed. Each key works on one store at a time, like a bought one. Cancel any time; keys from the subscription stop working when it ends. Packages you bought individually are always yours.</p>
            @if ($current)
                <p><strong>You are subscribed.</strong> Get your keys in your account.</p>
                <p><a class="button" href="{{ url('/account') }}">Go to your account</a></p>
            @elseif (auth()->check())
                <form method="post" action="{{ url('/subscribe') }}">@csrf<button type="submit">Subscribe · {{ $priceLabel }}</button></form>
            @else
                <p><a class="button" href="{{ url('/account/login?next='.rawurlencode('/subscription')) }}">Sign in to subscribe</a></p>
            @endif
        @else
            <p>The subscription is not open for new customers right now. Packages can still be bought individually.</p>
        @endif
    </section>

    <section class="related-packages">
        <div class="section-heading"><h2>Included in the subscription</h2><a href="{{ url('/?tier=subscription') }}">Browse in the catalog →</a></div>
        @include('partials.grid', ['products' => $included])
    </section>
@endsection
