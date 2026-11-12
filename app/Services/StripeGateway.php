<?php

namespace Marketplace\Services;

use Illuminate\Support\Facades\Http;
use Marketplace\Models\Order;
use Marketplace\Models\Setting;
use Marketplace\Models\Subscription;
use RuntimeException;

/**
 * Stripe hosted Checkout and webhook signature checks. Keys come from
 * admin settings (stored encrypted).
 */
class StripeGateway
{
    /** Webhook timestamps older than this are refused (replay protection). */
    public const TOLERANCE = 300;

    public function configured(): bool
    {
        return str_starts_with((string) Setting::get('stripe_secret', ''), 'sk_');
    }

    /**
     * Creates a Checkout session for the order and returns Stripe's URL.
     */
    public function createSession(Order $order, string $successUrl, string $cancelUrl): string
    {
        $order->loadMissing('package', 'user');
        $amount = (int) round(((float) $order->amount) * 100);

        $response = Http::asForm()
            ->withBasicAuth((string) Setting::get('stripe_secret', ''), '')
            ->timeout(20)
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $order->user->email,
                'client_reference_id' => (string) $order->id,
                'metadata' => ['order_id' => (string) $order->id, 'package' => $order->package->identifier],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($order->currency),
                        'unit_amount' => $amount,
                        'product_data' => ['name' => $order->package->name],
                    ],
                ]],
            ]);

        if (! $response->successful() || ! is_string($response->json('url'))) {
            throw new RuntimeException('Stripe did not create a checkout session: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        $order->forceFill(['stripe_session' => (string) $response->json('id')])->save();

        return (string) $response->json('url');
    }

    /**
     * Creates a Checkout session in subscription mode (monthly or yearly) and
     * returns Stripe's URL. Uses the plan's Stripe Price for that interval when
     * one is set, else a recurring price from the plan's amount and currency.
     *
     * @param  array{name: string, interval: string, price: string, currency: string, stripe_price: string}  $plan  Subscriptions::pricing()
     */
    public function createSubscriptionSession(Subscription $subscription, array $plan, string $successUrl, string $cancelUrl): string
    {
        $subscription->loadMissing('user');
        $id = (string) $subscription->id;

        $item = $plan['stripe_price'] !== ''
            ? ['quantity' => 1, 'price' => $plan['stripe_price']]
            : ['quantity' => 1, 'price_data' => [
                'currency' => strtolower($plan['currency']),
                'unit_amount' => (int) round(((float) $plan['price']) * 100),
                'recurring' => ['interval' => $plan['interval'] === 'year' ? 'year' : 'month'],
                'product_data' => ['name' => $plan['name']],
            ]];

        // A returning subscriber keeps their Stripe customer (and saved cards).
        $customer = Subscription::query()->where('user_id', $subscription->user_id)->whereNotNull('stripe_customer')->latest('id')->value('stripe_customer');

        $response = $this->post('checkout/sessions', [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            ...(is_string($customer) ? ['customer' => $customer] : ['customer_email' => $subscription->user->email]),
            'client_reference_id' => 'subscription-'.$id,
            'metadata' => ['subscription_id' => $id, 'interval' => $plan['interval']],
            // Copied onto the Stripe subscription, so its events find this row
            // even when they arrive before checkout.session.completed.
            'subscription_data' => ['metadata' => ['subscription_id' => $id]],
            'line_items' => [$item],
        ]);

        if (! is_string($response['url'] ?? null)) {
            throw new RuntimeException('Stripe did not create a checkout session.');
        }

        $subscription->forceFill(['stripe_session' => (string) $response['id']])->save();

        return (string) $response['url'];
    }

    /** A Stripe customer portal link (cancel, change card, invoices). */
    public function portalUrl(string $customer, string $returnUrl): string
    {
        $response = $this->post('billing_portal/sessions', ['customer' => $customer, 'return_url' => $returnUrl]);

        if (! is_string($response['url'] ?? null)) {
            throw new RuntimeException('Stripe did not open the customer portal.');
        }

        return (string) $response['url'];
    }

    /**
     * Cancels at the end of the paid period; Stripe sends
     * customer.subscription.updated now and customer.subscription.deleted then.
     *
     * @return array<string, mixed> the Stripe subscription
     */
    public function cancelAtPeriodEnd(string $subscription): array
    {
        return $this->post('subscriptions/'.rawurlencode($subscription), ['cancel_at_period_end' => 'true']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function post(string $path, array $data): array
    {
        $response = Http::asForm()
            ->withBasicAuth((string) Setting::get('stripe_secret', ''), '')
            ->timeout(20)
            ->post('https://api.stripe.com/v1/'.$path, $data);

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Stripe request failed: '.($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        return $response->json();
    }

    /**
     * Checks the Stripe-Signature header and returns the decoded event.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidWebhook
     */
    public function verify(string $payload, ?string $header): array
    {
        $secret = (string) Setting::get('stripe_webhook_secret', '');

        if ($secret === '' || $header === null) {
            throw new InvalidWebhook('Missing webhook secret or signature.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't') {
                $timestamp = (int) $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(time() - $timestamp) > self::TOLERANCE) {
            throw new InvalidWebhook('The webhook timestamp is outside the tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $event = json_decode($payload, true);

                if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
                    throw new InvalidWebhook('The webhook payload is not an event.');
                }

                return $event;
            }
        }

        throw new InvalidWebhook('The webhook signature does not match.');
    }
}
