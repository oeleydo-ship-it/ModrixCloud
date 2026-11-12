<?php

namespace Marketplace\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Marketplace\Models\Subscription;
use Marketplace\Services\StripeGateway;
use Marketplace\Services\Subscriptions;
use RuntimeException;

/**
 * The monthly subscription for customers: Stripe Checkout in subscription
 * mode, the Stripe customer portal and cancelling. Access starts and ends
 * with the webhook, not with these redirects.
 */
class SubscriptionController
{
    public function store(Request $request, Subscriptions $subscriptions, StripeGateway $stripe): RedirectResponse
    {
        $user = $request->user();

        if (! $subscriptions->available()) {
            return back()->withErrors(['subscription' => 'The subscription is not available right now.']);
        }

        if ($subscriptions->current($user) !== null) {
            return redirect('/account')->withErrors(['subscription' => 'You already have an active subscription.']);
        }

        if (! $stripe->configured()) {
            return back()->withErrors(['subscription' => 'Payments are not available yet. Please try again later.']);
        }

        $plan = $subscriptions->plan();
        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'amount' => $plan['price'],
            'currency' => $plan['currency'],
        ]);

        try {
            $url = $stripe->createSubscriptionSession($subscription, $plan, url('/account?subscribed=1'), url('/subscription'));
        } catch (RuntimeException $e) {
            report($e);
            $subscription->forceFill(['status' => 'expired'])->save();

            return back()->withErrors(['subscription' => 'The payment page could not be opened. Please try again.']);
        }

        return redirect()->away($url);
    }

    /** Stripe's customer portal: card, invoices, cancel or resume. */
    public function portal(Request $request, StripeGateway $stripe): RedirectResponse
    {
        $customer = Subscription::query()->where('user_id', $request->user()->id)->whereNotNull('stripe_customer')->latest('id')->value('stripe_customer');

        if (! is_string($customer) || ! $stripe->configured()) {
            return redirect('/account')->withErrors(['subscription' => 'There is no subscription billing to manage yet.']);
        }

        try {
            return redirect()->away($stripe->portalUrl($customer, url('/account')));
        } catch (RuntimeException $e) {
            report($e);

            return redirect('/account')->withErrors(['subscription' => 'The billing portal could not be opened. Please try again.']);
        }
    }

    /** Cancels at the end of the paid month; licenses keep working until then. */
    public function cancel(Request $request, Subscriptions $subscriptions, StripeGateway $stripe): RedirectResponse
    {
        $subscription = $subscriptions->current($request->user());

        if ($subscription === null || $subscription->stripe_subscription === null) {
            return redirect('/account')->withErrors(['subscription' => 'You have no active subscription to cancel.']);
        }

        try {
            $stripe->cancelAtPeriodEnd($subscription->stripe_subscription);
        } catch (RuntimeException $e) {
            report($e);

            return redirect('/account')->withErrors(['subscription' => 'The subscription could not be cancelled. Please try again.']);
        }

        $subscription->forceFill(['cancel_at_period_end' => true])->save();
        $until = $subscription->current_period_end?->toFormattedDateString();

        return redirect('/account')->with('notice', 'Your subscription is cancelled'.($until ? ' and ends on '.$until : ' at the end of the paid month').'. Licenses from it keep working until then.');
    }
}
