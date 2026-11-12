<?php

namespace Marketplace\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Marketplace\Models\Setting;
use Marketplace\Models\Subscription;

/** The subscription plan (Admin → Pricing) and the list of subscriptions. */
class SubscriptionAdminController
{
    public const STATUSES = ['active', 'trialing', 'past_due', 'pending', 'incomplete', 'unpaid', 'paused', 'canceled', 'expired'];

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : '';

        return view('admin.subscriptions', [
            'status' => $status,
            'statuses' => self::STATUSES,
            'counts' => Subscription::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'subscriptions' => Subscription::query()->with('user')
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->latest('id')->paginate(50)->withQueryString(),
        ]);
    }

    public function savePlan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subscription_enabled' => ['nullable', 'boolean'],
            'subscription_name' => ['required', 'string', 'max:120'],
            'subscription_price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'subscription_currency' => ['required', 'string', 'size:3', 'alpha'],
            'subscription_stripe_price' => ['nullable', 'string', 'max:255', 'regex:/^price_[A-Za-z0-9]+$/'],
        ], ['subscription_stripe_price.regex' => 'The Stripe Price ID starts with price_.']);

        $enabled = $request->boolean('subscription_enabled');

        if ($enabled && (float) $data['subscription_price'] <= 0) {
            return back()->withInput()->withErrors(['subscription_price' => 'Set a monthly price above zero to offer the subscription.']);
        }

        Setting::put('subscription_enabled', $enabled);
        Setting::put('subscription_name', $data['subscription_name']);
        Setting::put('subscription_price', number_format((float) $data['subscription_price'], 2, '.', ''));
        Setting::put('subscription_currency', strtoupper($data['subscription_currency']));
        Setting::put('subscription_stripe_price', (string) ($data['subscription_stripe_price'] ?? ''));

        return redirect('/admin/catalog')->with('notice', $enabled ? 'Subscription saved. Customers can subscribe.' : 'Subscription saved. New sign-ups are off; existing subscribers keep their access.');
    }
}
