<?php

namespace Marketplace\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Marketplace\Models\License;
use Marketplace\Models\Order;
use Marketplace\Models\StoreConnection;
use Marketplace\Services\Catalog;
use Marketplace\Services\Licensing;
use Marketplace\Services\Subscriptions;

/** The signed-in customer's licenses, connected stores and orders. */
class AccountController
{
    public function show(Request $request, Catalog $catalog, Subscriptions $subscriptions): View
    {
        $user = $request->user();
        $selected = $catalog->find((string) $request->query('product', ''));
        $subscription = $subscriptions->current($user);

        return view('account.show', [
            'user' => $user,
            'licenses' => License::query()->where('user_id', $user->id)->with(['package', 'activations' => fn ($q) => $q->where('active', true)])->latest()->get(),
            'orders' => Order::query()->where('user_id', $user->id)->with('package')->latest()->limit(50)->get(),
            'selected' => $selected,
            'free' => $catalog->published()->filter(fn ($p) => ! $p['premium'])->sortBy('name')->values(),
            // Packages the customer's subscription gives them a license for.
            'included' => $subscription === null ? collect() : $catalog->published()->filter(fn ($p) => $p['model']->inSubscription())->sortBy('name')->values(),
            'subscription' => $subscription ?? $subscriptions->latest($user),
            'subscriptionLive' => $subscription !== null,
            'plan' => $subscriptions->available() ? $subscriptions->offerLabel() : null,
            'intervals' => $subscriptions->intervals(),
            'priceLabels' => ['month' => $subscriptions->priceLabel('month'), 'year' => $subscriptions->priceLabel('year')],
            'pendingSubscription' => $subscription === null && $request->boolean('subscribed'),
            'connections' => StoreConnection::query()->where('user_id', $user->id)->whereNull('revoked_at')->latest()->get(),
        ]);
    }

    /** A license for a free package, a paid one already bought, or one included in the subscription. */
    public function license(Request $request, Catalog $catalog, Licensing $licensing, Subscriptions $subscriptions): RedirectResponse
    {
        $request->validate(['product' => ['required', 'string']]);
        $product = $catalog->find((string) $request->input('product'));

        if ($product === null) {
            throw ValidationException::withMessages(['product' => 'Choose a package from the marketplace.']);
        }

        $license = $product['premium']
            ? $subscriptions->claim($request->user(), $product['model'])
            : $licensing->issue($request->user(), $product['model'], 'free');

        if ($license === null) {
            throw ValidationException::withMessages(['product' => $product['in_subscription'] && $subscriptions->available()
                ? 'Buy this package or subscribe; its license key comes with either.'
                : 'Buy this package first; its license key is issued with the purchase.']);
        }

        if ($license->status === 'expired' && $license->type === 'subscription') {
            throw ValidationException::withMessages(['product' => 'This key came with your subscription, which has ended. Subscribe again or buy the package to use it.']);
        }

        if ($license->status !== 'active') {
            throw ValidationException::withMessages(['product' => 'Your license for this package was '.$license->status.'. Contact support if you think this is a mistake.']);
        }

        return redirect('/account')->with('notice', 'License ready for '.$product['name'].'.');
    }

    /** Frees a key from its store so it can be activated on another one. */
    public function release(Request $request): RedirectResponse
    {
        $request->validate(['license_id' => ['required', 'integer']]);
        $license = License::query()->findOrFail((int) $request->input('license_id'));

        abort_unless($license->user_id === $request->user()->id, 403);

        $license->activations()->where('active', true)->update(['active' => false, 'deactivated_at' => now()]);

        return redirect('/account')->with('notice', 'The store was disconnected. You can activate the key on another store.');
    }
}
