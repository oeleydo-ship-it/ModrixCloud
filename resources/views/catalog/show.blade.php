@extends('layouts.site', ['title' => $product['name']])

@section('content')
    @php($kind = $product['type'] === 'theme' ? 'Theme' : 'Extension')
    <nav class="detail-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ url('/') }}">Marketplace</a><span>/</span><a href="{{ url('/?type='.$product['type']) }}">{{ $kind }}s</a><span>/</span><span>{{ $product['name'] }}</span>
    </nav>
    <div class="detail-title">
        <div>
            <span class="eyebrow">{{ strtoupper($kind) }} FOR MODRIX COMMERCE</span>
            <h1>{{ $product['name'] }}</h1>
            <p>By <strong>{{ $product['author'] }}</strong> <span>· Version {{ $product['version'] }}</span></p>
        </div>
        @include('partials.tags', ['product' => $product])
    </div>
    <div class="product-layout">
        <div>
            <div class="product-preview">
                @include('partials.visual', ['product' => $product])
                <div class="preview-caption">
                    <span>{{ $product['name'] }} · {{ $product['type'] === 'theme' ? 'Storefront preview' : 'Extension overview' }}</span>
                    @if (! empty($product['demo_url']))
                        <a href="{{ $product['demo_url'] }}" target="_blank" rel="noopener noreferrer">Open live demo ↗</a>
                    @else
                        <span>No live demo provided</span>
                    @endif
                </div>
            </div>
            <section class="product-description">
                <h2>About this {{ $kind }}</h2>
                <p>{!! nl2br(e($product['summary'])) !!}</p>
                @if (! empty($product['features']))
                    <h3>Key features</h3>
                    <ul class="feature-list">
                        @foreach ($product['features'] as $feature)<li>{{ $feature }}</li>@endforeach
                    </ul>
                @endif
                @if (! empty($product['changelog']))
                    <h3>Latest release</h3>
                    <p>{!! nl2br(e($product['changelog'])) !!}</p>
                @endif
            </section>
        </div>
        <aside class="purchase-panel">
            <span class="package-kind">{{ $product['premium'] ? 'PAID '.strtoupper($kind) : 'FREE '.strtoupper($kind) }}</span>
            @if ($product['in_subscription'])<span class="subscription-badge">Included in subscription</span>@endif
            <p class="detail-price">{{ $product['price_label'] }}</p>
            <p class="meta">
                @if (! $product['premium'])
                    Start building without a package fee.
                @elseif ($product['sold_individually'] && $product['in_subscription'])
                    One-time purchase in {{ $currency }}, or included in the subscription ({{ $plan }}).
                @elseif ($product['sold_individually'])
                    One-time purchase in {{ $currency }}.
                @elseif ($product['in_subscription'])
                    Available with the subscription ({{ $plan }}).
                @else
                    Not available to new customers right now.
                @endif
            </p>
            @if (! empty($product['demo_url']))
                <a class="button demo-button" href="{{ $product['demo_url'] }}" target="_blank" rel="noopener noreferrer">View live demo ↗</a>
            @endif
            @if ($product['premium'] && ! $owned && ! $covered)
                <p class="purchase-license-note">{{ $product['in_subscription'] ? ($product['sold_individually'] ? 'Buy this item or subscribe' : 'Subscribe') : 'Purchase this item first' }} to get a license key in your account.</p>
            @endif
            @if ($owned)
                <p>{{ $ownedVia === 'subscription' ? 'Included in your subscription.' : 'You own this product.' }} The license key is in your account.</p>
                <a class="button" href="{{ url('/account?product='.$product['slug']) }}">Manage license ↗</a>
            @elseif (! $product['premium'])
                <p>This product is free.</p>
                <a class="button" href="{{ auth()->check() ? url('/account?product='.$product['slug']) : url('/account/login?next='.rawurlencode('/account?product='.$product['slug'])) }}">Generate a free license ↗</a>
            @else
                @if ($covered)
                    <p>Included in your subscription.</p>
                    <a class="button" href="{{ url('/account?product='.$product['slug']) }}">Get your license ↗</a>
                @endif
                @if ($product['sold_individually'])
                    @if (! auth()->check())
                        <a class="button" href="{{ url('/account/login?next='.rawurlencode('/item/'.$product['slug'])) }}">Buy now</a>
                    @else
                        <form method="post" action="{{ url('/buy/'.$product['slug']) }}">@csrf<button type="submit" @class(['secondary' => $covered])>{{ $covered ? 'Buy to keep it' : 'Buy now' }}</button></form>
                    @endif
                @endif
                @if ($product['in_subscription'] && ! $covered)
                    <a class="button{{ $product['sold_individually'] ? ' secondary' : '' }}" href="{{ url('/subscription') }}">Subscribe · {{ $plan }}</a>
                @endif
            @endif
            @if ($owned && $ownedVia === 'subscription' && $product['sold_individually'])
                <form method="post" action="{{ url('/buy/'.$product['slug']) }}">@csrf<button class="secondary" type="submit">Buy to keep it after the subscription</button></form>
            @endif
            @if ($product['has_release'] && (! $product['premium'] || $owned))
                <a class="button secondary" href="{{ url('/download/'.$product['slug']) }}">Download package ZIP ↓</a>
            @endif
            <p class="license-note">One active store per license. Manage your key and connected store from your account.</p>
            <dl class="package-specs">
                @foreach (['Version' => $product['version'], 'Requires core' => $product['platform_version'], 'Latest core' => $core['latest_core'] ?? '—', 'License' => $product['license'], 'Publisher' => $product['author'], 'Package' => $product['identifier']] as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if ($core['minimum_core'] === null && $core['latest_core'] !== null)
                <p class="license-note">This version needs Modrix Commerce core {{ $product['platform_version'] }}, which is not released yet (latest core: {{ $core['latest_core'] }}).</p>
            @elseif ($core['minimum_core'] !== null && $core['minimum_core'] !== $core['latest_core'])
                <p class="license-note">Runs on Modrix Commerce core {{ $core['minimum_core'] }} or later. Update your store's core first if it is older.</p>
            @elseif ($core['minimum_core'] !== null)
                <p class="license-note">Needs Modrix Commerce core {{ $core['minimum_core'] }}. Update your store's core first if it is older.</p>
            @endif
            @if (! empty($product['repository_url']))
                <a class="source-link" href="{{ $product['repository_url'] }}" target="_blank" rel="noopener noreferrer">Source repository ↗</a>
            @endif
        </aside>
    </div>
    @if (count($related) > 0)
        <section class="related-packages">
            <div class="section-heading"><h2>More {{ strtolower($kind) }}s to explore</h2><a href="{{ url('/?type='.$product['type']) }}">View all →</a></div>
            @include('partials.grid', ['products' => $related])
        </section>
    @endif
@endsection
