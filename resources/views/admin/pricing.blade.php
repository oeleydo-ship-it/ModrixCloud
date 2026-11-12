@extends('layouts.admin', ['title' => 'Pricing'])

@section('admin')
    <h1>Pricing</h1>

    <h2>Subscription (monthly or yearly)</h2>
    <p class="meta">Subscribers get a license key for every paid package marked as included below, for as long as the subscription is active. Licenses bought individually are never affected. {{ $subscribers }} active {{ $subscribers === 1 ? 'subscription' : 'subscriptions' }} · <a href="{{ url('/admin/subscriptions') }}">View subscriptions →</a></p>
    <form method="post" action="{{ url('/admin/subscription-plan') }}" class="form-grid">
        @csrf
        <input type="hidden" name="subscription_enabled" value="0">
        <label class="check wide"><input type="checkbox" name="subscription_enabled" value="1" @checked(old('subscription_enabled', $plan['enabled']))> Offer the subscription</label>
        <label class="wide">Plan name <input name="subscription_name" value="{{ old('subscription_name', $plan['name']) }}" required maxlength="120"></label>
        <label>Monthly price <input name="subscription_price" type="number" min="0" step="0.01" value="{{ old('subscription_price', $plan['price']) }}" required></label>
        <label>Currency (ISO code) <input name="subscription_currency" value="{{ old('subscription_currency', $plan['currency']) }}" required maxlength="3" pattern="[A-Za-z]{3}"></label>
        <label>Yearly price (0 = monthly only) <input name="subscription_yearly_price" type="number" min="0" step="0.01" value="{{ old('subscription_yearly_price', $plan['yearly_price']) }}"></label>
        <label>Stripe monthly Price ID (optional) <input name="subscription_stripe_price" value="{{ old('subscription_stripe_price', $plan['stripe_price']) }}" placeholder="price_…" autocomplete="off"></label>
        <label>Stripe yearly Price ID (optional) <input name="subscription_stripe_price_yearly" value="{{ old('subscription_stripe_price_yearly', $plan['stripe_price_yearly']) }}" placeholder="price_…" autocomplete="off"></label>
        <p class="wide meta">Customers choose monthly or yearly billing when they subscribe; yearly is offered when it has a price. Leave a Price ID blank and checkout charges the price and currency above every month or every year. To manage prices in Stripe instead, create a product with a monthly and a yearly recurring price in the Stripe dashboard and paste their Price IDs; keep the prices above the same, since they are what the catalog shows. Add the webhook events <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code> and <code>invoice.payment_failed</code> in Stripe (Settings → Payments lists them all), and turn on the customer portal in Stripe's billing settings so customers can manage their card.</p>
        <div class="wide"><button type="submit">Save subscription</button></div>
    </form>

    <h2>Packages</h2>
    <p class="meta">Set every package's price in one place. Prices are in {{ app(\Marketplace\Services\Catalog::class)->currency() }}. For paid packages, choose whether they are sold individually, included in the subscription, or both.</p>
    <form method="post" action="{{ url('/admin/catalog') }}">
        @csrf
        <div class="table-wrap">
            <table>
                <thead><tr><th>Package</th><th>Tier</th><th>Price</th><th>Paid packages are</th></tr></thead>
                <tbody>
                @foreach ($packages as $package)
                    <tr>
                        <td>{{ $package->name }}<br><span class="meta">{{ $package->identifier }}</span></td>
                        <td>
                            <select name="prices[{{ $package->id }}][tier]" aria-label="Tier for {{ $package->name }}">
                                <option value="free" @selected($package->is_free)>Free</option>
                                <option value="premium" @selected(! $package->is_free)>Paid</option>
                            </select>
                        </td>
                        <td><input name="prices[{{ $package->id }}][price]" type="number" min="0" step="0.01" value="{{ $package->price }}" aria-label="Price for {{ $package->name }}"></td>
                        <td>
                            <select name="prices[{{ $package->id }}][sale]" aria-label="How {{ $package->name }} is sold">
                                @foreach (['individual' => 'Sold individually', 'subscription' => 'Subscription only', 'both' => 'Both'] as $value => $label)
                                    <option value="{{ $value }}" @selected($package->saleMode() === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <button type="submit">Save prices</button>
    </form>
@endsection
