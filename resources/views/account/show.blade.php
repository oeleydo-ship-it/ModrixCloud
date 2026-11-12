@extends('layouts.site', ['title' => 'Your account'])

@section('content')
    <div class="account-heading">
        <div>
            <span class="eyebrow">YOUR ACCOUNT</span>
            <h1>Hello, {{ $user->name }}</h1>
            <p class="meta">{{ $user->email }}</p>
        </div>
        <form method="post" action="{{ url('/account/logout') }}">@csrf<button class="secondary" type="submit">Log out</button></form>
    </div>

    @if ($subscription || $plan || $pendingSubscription)
        <section class="panel subscription-panel">
            <h2>Monthly subscription</h2>
            @if ($subscriptionLive)
                <p>
                    <span class="status status-{{ $subscription->status }}">{{ ucfirst(str_replace('_', ' ', $subscription->status)) }}</span>
                    {{ $subscription->currency }} {{ $subscription->amount }} / month.
                    @if ($subscription->current_period_end)
                        {{ $subscription->cancel_at_period_end ? 'Ends on' : 'Renews on' }} {{ $subscription->current_period_end->toFormattedDateString() }}.
                    @endif
                </p>
                @if ($subscription->status === 'past_due')
                    <p class="settings-warning" role="alert">The last payment failed. Update your card in billing so your subscription keys keep working.</p>
                @endif
                <p class="meta">Get a key below for any of the {{ $included->count() }} {{ $included->count() === 1 ? 'package' : 'packages' }} included in your subscription. Keys from the subscription stop working when it ends; packages you bought are always yours.</p>
                <div class="actions">
                    <form method="post" action="{{ url('/account/subscription/portal') }}">@csrf<button class="secondary" type="submit">Manage billing</button></form>
                    @if (! $subscription->cancel_at_period_end)
                        <form method="post" action="{{ url('/account/subscription/cancel') }}" onsubmit="return confirm('Cancel your subscription? Keys from it keep working until the end of the paid month.')">@csrf<button class="secondary" type="submit">Cancel subscription</button></form>
                    @endif
                </div>
            @elseif ($pendingSubscription)
                <p>Thanks! We are confirming your payment with Stripe. Refresh this page in a moment to get your keys.</p>
            @else
                @if ($subscription)
                    <p class="meta">Your subscription is {{ str_replace('_', ' ', $subscription->status) }}{{ $subscription->ended_at ? ' since '.$subscription->ended_at->toFormattedDateString() : '' }}. Keys from it no longer work.</p>
                @endif
                @if ($plan)
                    <p>Subscribe for {{ $plan }} and get a key for every package included in the subscription.</p>
                    <div class="actions">
                        <form method="post" action="{{ url('/subscribe') }}">@csrf<button type="submit">Subscribe</button></form>
                        <a class="button secondary" href="{{ url('/subscription') }}">See what is included</a>
                    </div>
                @endif
                @if ($subscription?->stripe_customer)
                    <form method="post" action="{{ url('/account/subscription/portal') }}">@csrf<button class="link-button" type="submit">Billing history</button></form>
                @endif
            @endif
        </section>
    @endif

    <section class="panel">
        <h2>Get a license key</h2>
        @if ($selected && ($selected['premium'] === false || $licenses->contains('package_id', $selected['id']) || ($subscriptionLive && $selected['model']->inSubscription())))
            <p>Generate the key for <strong>{{ $selected['name'] }}</strong>, then add it under Admin → Modrix Cloud in your store, or connect the store to install without copying keys.</p>
        @elseif ($selected)
            <p><strong>{{ $selected['name'] }}</strong> is a paid package. <a href="{{ url('/item/'.$selected['slug']) }}">{{ $selected['in_subscription'] ? 'Buy it or subscribe' : 'Buy it first' }}</a>; the key is issued with the purchase{{ $selected['in_subscription'] ? ' or the subscription' : '' }}.</p>
        @else
            <p class="meta">Choose a free package{{ $subscriptionLive ? ' or one included in your subscription' : '' }}. Paid packages get their key when you buy them.</p>
        @endif
        <form method="post" action="{{ url('/account/licenses') }}" class="inline-form">
            @csrf
            <label class="sr-only" for="license-product">Package</label>
            <select id="license-product" name="product" required>
                @if ($selected)
                    <option value="{{ $selected['slug'] }}" selected>{{ $selected['name'] }}</option>
                @endif
                @if ($included->isNotEmpty())
                    <optgroup label="Included in your subscription">
                        @foreach ($included as $product)
                            @continue($selected && $product['slug'] === $selected['slug'])
                            <option value="{{ $product['slug'] }}">{{ $product['name'] }}</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="Free packages">
                @endif
                @foreach ($free as $product)
                    @continue($selected && $product['slug'] === $selected['slug'])
                    <option value="{{ $product['slug'] }}">{{ $product['name'] }}</option>
                @endforeach
                @if ($included->isNotEmpty())
                    </optgroup>
                @endif
            </select>
            <button type="submit">Generate license</button>
        </form>
    </section>

    <section class="panel">
        <h2>Your licenses</h2>
        @if ($licenses->isEmpty())
            <p class="meta">No licenses yet. <a href="{{ url('/') }}">Browse the marketplace</a>.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Package</th><th>License key</th><th>Status</th><th>Connected store</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($licenses as $license)
                        @php($activation = $license->activations->first())
                        <tr>
                            <td><a href="{{ url('/item/'.$license->package->slug) }}">{{ $license->package->name }}</a><br><span class="meta">{{ ['paid' => 'Purchased', 'subscription' => 'Subscription', 'free' => 'Free'][$license->type] ?? ucfirst($license->type) }}</span></td>
                            <td><code class="license-key">{{ $license->key }}</code></td>
                            <td>{{ $license->status === 'expired' && $license->type === 'subscription' ? 'Subscription ended' : ucfirst($license->status) }}</td>
                            <td>{{ $activation ? ($activation->domain ?: $activation->instance_id) : 'Not connected' }}</td>
                            <td>
                                @if ($activation)
                                    <form method="post" action="{{ url('/account/licenses/release') }}">@csrf<input type="hidden" name="license_id" value="{{ $license->id }}"><button class="secondary small" type="submit">Disconnect store</button></form>
                                @endif
                                @if ($license->status === 'active' && $license->package->releases()->exists())
                                    <a class="small" href="{{ url('/download/'.$license->package->slug) }}">Download ZIP</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="meta">A key works on one store at a time. Disconnect a store to move the key to another one.</p>
        @endif
    </section>

    <section class="panel">
        <h2>Connected stores</h2>
        @if ($connections->isEmpty())
            <p class="meta">No store is connected. In your store, open Modrix Cloud and choose Connect to install your packages without copying keys.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Store</th><th>Connected</th><th>Last used</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($connections as $connection)
                        <tr>
                            <td>{{ $connection->store_name }}<br><span class="meta">{{ $connection->domain }}</span></td>
                            <td>{{ $connection->created_at?->toFormattedDateString() }}</td>
                            <td>{{ $connection->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                            <td>
                                <form method="post" action="{{ url('/account/connections/'.$connection->id.'/revoke') }}">@csrf<button class="secondary small" type="submit">Disconnect</button></form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($orders->isNotEmpty())
        <section class="panel">
            <h2>Orders</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Package</th><th>Amount</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($orders as $order)
                        <tr><td>{{ $order->created_at->format('Y-m-d') }}</td><td>{{ $order->package->name }}</td><td>{{ $order->currency }} {{ $order->amount }}</td><td>{{ ucfirst($order->status) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="panel">
        <h2>Publishing</h2>
        <p class="meta">Built a theme or extension? Submit it for review.</p>
        <p><a class="button secondary" href="{{ url('/submit') }}">Submit a package</a> <a class="button secondary" href="{{ url('/submit/update') }}">Submit an update</a> <a href="{{ url('/submissions') }}">Your submissions →</a></p>
    </section>
@endsection
