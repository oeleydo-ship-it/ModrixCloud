<?php

namespace Marketplace\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Marketplace\Mail\LicenseIssued;
use Marketplace\Models\Order;
use Marketplace\Models\Subscription;
use Marketplace\Models\WebhookEvent;
use Marketplace\Services\InvalidWebhook;
use Marketplace\Services\Licensing;
use Marketplace\Services\StripeGateway;
use Marketplace\Services\Subscriptions;

/**
 * POST /stripe/webhook. Each event is handled once (webhook_events); repeats
 * and events about unknown orders are acknowledged without side effects.
 * Subscription events keep the monthly subscription (and its licenses) in sync.
 */
class StripeWebhookController
{
    public function __invoke(Request $request, StripeGateway $stripe, Licensing $licensing, Subscriptions $subscriptions): JsonResponse
    {
        try {
            $event = $stripe->verify($request->getContent(), $request->header('Stripe-Signature'));
        } catch (InvalidWebhook $e) {
            return response()->json(['error' => 'invalid_signature', 'message' => $e->getMessage()], 400);
        }

        $object = (array) ($event['data']['object'] ?? []);
        $mail = null;

        // The event is recorded in the same transaction as its effects: if
        // handling fails, nothing is recorded and Stripe's retry runs it again.
        try {
            $duplicate = DB::transaction(function () use ($event, $object, $licensing, $subscriptions, &$mail) {
                if (WebhookEvent::query()->where('provider', 'stripe')->where('event_id', $event['id'])->exists()) {
                    return true;
                }

                WebhookEvent::query()->create(['provider' => 'stripe', 'event_id' => $event['id'], 'type' => $event['type'], 'processed_at' => now()]);

                match ($event['type']) {
                    // Delayed methods (bank debits) complete unpaid, then confirm later.
                    'checkout.session.completed', 'checkout.session.async_payment_succeeded' => ($object['mode'] ?? null) === 'subscription'
                        ? $this->subscribed($object, $subscriptions)
                        : $mail = $this->completed($object, $licensing),
                    'checkout.session.expired', 'checkout.session.async_payment_failed' => $this->expired($object),
                    'charge.refunded' => $this->refunded($object),
                    'customer.subscription.created', 'customer.subscription.updated' => $this->subscriptionChanged($object, $subscriptions, false),
                    'customer.subscription.deleted' => $this->subscriptionChanged($object, $subscriptions, true),
                    'invoice.payment_failed' => $this->paymentFailed($object),
                    default => null,
                };

                return false;
            });
        } catch (UniqueConstraintViolationException) {
            $duplicate = true; // A concurrent delivery of the same event recorded it first.
        }

        if ($duplicate) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        if ($mail instanceof Order) {
            Mail::to($mail->user->email)->queue(new LicenseIssued($mail));
        }

        return response()->json(['received' => true]);
    }

    /** Marks the order paid and issues (or returns) its license. */
    private function completed(array $session, Licensing $licensing): ?Order
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }

        $order = Order::query()->with(['user', 'package'])->where('stripe_session', $session['id'] ?? '')->lockForUpdate()->first();

        if (! $order instanceof Order || $order->status === 'paid') {
            return null;
        }

        $license = $licensing->issue($order->user, $order->package, 'paid');
        $license->forceFill(['type' => 'paid', 'status' => 'active'])->save();
        $order->forceFill([
            'status' => 'paid',
            'paid_at' => now(),
            'license_id' => $license->id,
            'stripe_payment_intent' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
        ])->save();

        return $order;
    }

    private function expired(array $session): void
    {
        Order::query()->where('stripe_session', $session['id'] ?? '')->where('status', 'pending')->update(['status' => 'expired']);
        Subscription::query()->where('stripe_session', $session['id'] ?? '')->where('status', 'pending')->update(['status' => 'expired']);
    }

    /** A paid subscription checkout: links the Stripe subscription and starts access. */
    private function subscribed(array $session, Subscriptions $subscriptions): void
    {
        if (! in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true)) {
            return;
        }

        $subscription = Subscription::query()->with('user')->where('stripe_session', $session['id'] ?? '')->lockForUpdate()->first();

        if (! $subscription instanceof Subscription) {
            return;
        }

        $subscription->forceFill([
            'stripe_subscription' => $subscription->stripe_subscription ?? $this->id($session['subscription'] ?? null),
            'stripe_customer' => $this->id($session['customer'] ?? null) ?? $subscription->stripe_customer,
            // A subscription event may already have set the live status.
            'status' => in_array($subscription->status, ['pending', 'incomplete', 'expired'], true) ? 'active' : $subscription->status,
        ])->save();

        $subscriptions->syncUser($subscription->user);
    }

    /** customer.subscription.* : status, renewal date and cancellation. */
    private function subscriptionChanged(array $object, Subscriptions $subscriptions, bool $deleted): void
    {
        $stripeId = $this->id($object['id'] ?? null);
        $subscription = $stripeId === null ? null : Subscription::query()->with('user')->where('stripe_subscription', $stripeId)->lockForUpdate()->first();

        // Events can arrive before checkout.session.completed: find the row from the metadata set at checkout.
        if (! $subscription instanceof Subscription && is_numeric($object['metadata']['subscription_id'] ?? null)) {
            $subscription = Subscription::query()->with('user')->whereKey((int) $object['metadata']['subscription_id'])->whereNull('stripe_subscription')->lockForUpdate()->first();
        }

        // Canceled is final in Stripe; a late "updated" event does not bring it back.
        if (! $subscription instanceof Subscription || ($subscription->status === 'canceled' && ! $deleted)) {
            return;
        }

        $status = $deleted ? 'canceled' : (string) ($object['status'] ?? '');

        if (! in_array($status, ['active', 'trialing', 'past_due', 'incomplete', 'incomplete_expired', 'unpaid', 'canceled', 'paused'], true)) {
            return;
        }

        // Newer API versions put the period on the subscription item.
        $periodEnd = $object['current_period_end'] ?? $object['items']['data'][0]['current_period_end'] ?? null;
        $endedAt = $object['ended_at'] ?? null;

        $subscription->forceFill([
            'stripe_subscription' => $stripeId,
            'stripe_customer' => $this->id($object['customer'] ?? null) ?? $subscription->stripe_customer,
            'status' => $status === 'incomplete_expired' ? 'expired' : $status,
            'cancel_at_period_end' => (bool) ($object['cancel_at_period_end'] ?? false),
            'current_period_end' => is_numeric($periodEnd) ? Carbon::createFromTimestamp((int) $periodEnd) : $subscription->current_period_end,
            'ended_at' => is_numeric($endedAt) ? Carbon::createFromTimestamp((int) $endedAt) : ($deleted ? now() : null),
        ])->save();

        $subscriptions->syncUser($subscription->user);
    }

    /**
     * A failed renewal: past_due while Stripe retries. Access continues until
     * Stripe cancels the subscription or marks it unpaid.
     */
    private function paymentFailed(array $invoice): void
    {
        $stripeId = $this->id($invoice['subscription'] ?? null) ?? $this->id($invoice['parent']['subscription_details']['subscription'] ?? null);

        if ($stripeId === null) {
            return;
        }

        Subscription::query()->where('stripe_subscription', $stripeId)->whereIn('status', ['active', 'trialing'])->update(['status' => 'past_due']);
    }

    /** Stripe sends ids, or objects when expanded. */
    private function id(mixed $value): ?string
    {
        $value = is_array($value) ? ($value['id'] ?? null) : $value;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A refunded payment revokes its license; the store's next check sees it. */
    private function refunded(array $charge): void
    {
        $intent = $charge['payment_intent'] ?? null;

        if (! is_string($intent) || ($charge['refunded'] ?? false) !== true) {
            return;
        }

        $order = Order::query()->where('stripe_payment_intent', $intent)->lockForUpdate()->first();

        if (! $order instanceof Order) {
            return;
        }

        $order->forceFill(['status' => 'refunded'])->save();

        // A license still covered by another paid order (a repeat purchase) stays active.
        $stillPaid = $order->license_id !== null && Order::query()->where('license_id', $order->license_id)->where('status', 'paid')->exists();

        if (! $stillPaid) {
            $order->license?->forceFill(['status' => 'refunded'])->save();
        }
    }
}
