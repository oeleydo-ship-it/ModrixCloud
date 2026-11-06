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

    <section class="panel">
        <h2>Get a license key</h2>
        @if ($selected && ($selected['premium'] === false || $licenses->contains('package_id', $selected['id'])))
            <p>Generate the key for <strong>{{ $selected['name'] }}</strong>, then add it under Admin → Modrix Cloud in your store, or connect the store to install without copying keys.</p>
        @elseif ($selected)
            <p><strong>{{ $selected['name'] }}</strong> is a paid package. <a href="{{ url('/item/'.$selected['slug']) }}">Buy it first</a>; the key is issued with the purchase.</p>
        @else
            <p class="meta">Choose a free package. Paid packages get their key when you buy them.</p>
        @endif
        <form method="post" action="{{ url('/account/licenses') }}" class="inline-form">
            @csrf
            <label class="sr-only" for="license-product">Package</label>
            <select id="license-product" name="product" required>
                @if ($selected)
                    <option value="{{ $selected['slug'] }}" selected>{{ $selected['name'] }}</option>
                @endif
                @foreach ($free as $product)
                    @continue($selected && $product['slug'] === $selected['slug'])
                    <option value="{{ $product['slug'] }}">{{ $product['name'] }}</option>
                @endforeach
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
                            <td><a href="{{ url('/item/'.$license->package->slug) }}">{{ $license->package->name }}</a><br><span class="meta">{{ ucfirst($license->type) }}</span></td>
                            <td><code class="license-key">{{ $license->key }}</code></td>
                            <td>{{ ucfirst($license->status) }}</td>
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
