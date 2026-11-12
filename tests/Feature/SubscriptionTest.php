<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Marketplace\Models\License;
use Marketplace\Models\LicenseActivation;
use Marketplace\Models\Order;
use Marketplace\Models\Package;
use Marketplace\Models\PackageRelease;
use Marketplace\Models\Setting;
use Marketplace\Models\Subscription;
use Marketplace\Models\User;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The monthly subscription next to individual purchases: per-package
 * eligibility, Stripe Checkout in subscription mode, the webhook keeping the
 * subscription in sync, subscription licenses and the store API.
 */
class SubscriptionTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('stripe_secret', 'sk_test_123', secret: true);
        Setting::put('stripe_webhook_secret', self::SECRET, secret: true);
        Setting::put('subscription_enabled', true);
        Setting::put('subscription_name', 'Modrix Cloud Pro');
        Setting::put('subscription_price', '19.00');
        Setting::put('subscription_currency', 'USD');
        $this->customer = $this->customer();
        Mail::fake();
    }

    private function paid(string $sale, array $attributes = []): Package
    {
        $individual = $sale !== 'subscription';

        return $this->package(['is_free' => false, 'price' => $individual ? '49.00' : '0', 'sold_individually' => $individual, 'in_subscription' => $sale !== 'individual'] + $attributes);
    }

    private function deliver(array $event)
    {
        $payload = json_encode($event);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$payload, self::SECRET);

        return $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}", 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    /** A pending subscription as created by /subscribe, then Stripe's events. */
    private function subscribe(?User $user = null): Subscription
    {
        $user ??= $this->customer;
        $subscription = Subscription::query()->create(['user_id' => $user->id, 'status' => 'pending', 'amount' => '19.00', 'currency' => 'USD', 'stripe_session' => 'cs_sub_'.$user->id]);

        $this->deliver(['id' => 'evt_done_'.$user->id, 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_sub_'.$user->id, 'mode' => 'subscription', 'payment_status' => 'paid', 'subscription' => 'sub_'.$user->id, 'customer' => 'cus_'.$user->id,
        ]]])->assertOk();

        return $subscription->fresh();
    }

    private function stripeSubscription(User $user, string $status, array $extra = []): array
    {
        return array_replace(['id' => 'sub_'.$user->id, 'object' => 'subscription', 'status' => $status, 'customer' => 'cus_'.$user->id, 'cancel_at_period_end' => false, 'items' => ['data' => [['current_period_end' => 1893456000]]], 'metadata' => []], $extra);
    }

    private function cancel(User $user): void
    {
        $this->deliver(['id' => 'evt_del_'.$user->id, 'type' => 'customer.subscription.deleted', 'data' => ['object' => $this->stripeSubscription($user, 'canceled', ['ended_at' => time()])]])->assertOk();
    }

    private function activate(License $license, string $instance = 'store-a'): void
    {
        $this->postJson('/api/marketplace/v1/licenses/activate', ['key' => $license->key, 'instance_id' => $instance])->assertOk();
    }

    // Eligibility

    public function test_admin_chooses_per_package_how_it_is_sold()
    {
        $admin = $this->admin();
        $a = $this->paid('individual');
        $b = $this->paid('individual');
        $c = $this->paid('individual');
        $free = $this->package();

        $this->actingAs($admin)->get('/admin/catalog')->assertOk()->assertSee('Monthly subscription')->assertSee('Subscription only');
        $this->post('/admin/catalog', ['prices' => [
            $a->id => ['tier' => 'premium', 'price' => '49', 'sale' => 'individual'],
            $b->id => ['tier' => 'premium', 'price' => '', 'sale' => 'subscription'],
            $c->id => ['tier' => 'premium', 'price' => '29', 'sale' => 'both'],
            $free->id => ['tier' => 'free', 'price' => '0', 'sale' => 'both'],
        ]])->assertRedirect('/admin/catalog');

        $this->assertSame('individual', $a->fresh()->saleMode());
        $this->assertTrue($a->fresh()->buyable());
        $this->assertSame('subscription', $b->fresh()->saleMode());
        $this->assertFalse($b->fresh()->is_free);
        $this->assertFalse($b->fresh()->buyable());
        $this->assertTrue($b->fresh()->inSubscription());
        $this->assertSame('both', $c->fresh()->saleMode());
        $this->assertTrue($c->fresh()->buyable() && $c->fresh()->inSubscription());
        // Free packages are free for everyone, not part of the subscription.
        $this->assertTrue($free->fresh()->is_free);
        $this->assertFalse($free->fresh()->inSubscription());
    }

    public function test_the_listing_editor_sets_the_sale_mode()
    {
        $package = $this->paid('individual', ['slug' => 'acme-seo', 'identifier' => 'acme/seo']);

        $this->actingAs($this->admin())->get('/admin/repository/acme-seo')->assertOk()->assertSee('Included in the subscription only');
        $this->post('/admin/repository/acme-seo', [
            'name' => 'SEO', 'summary' => 'Search', 'tier' => 'premium', 'price' => '39.00', 'sale' => 'both', 'status' => 'published', 'version' => '1.0.0',
        ])->assertRedirect('/admin/repository/acme-seo');

        $this->assertSame('both', $package->fresh()->saleMode());
        $this->assertSame('39.00', $package->fresh()->price);
    }

    public function test_subscription_only_packages_cannot_be_bought_individually()
    {
        $this->paid('subscription', ['slug' => 'acme-sub']);
        Http::fake();

        $this->actingAs($this->customer)->post('/buy/acme-sub')->assertNotFound();
        Http::assertNothingSent();
    }

    // Catalog

    public function test_catalog_and_item_pages_show_subscription_and_price()
    {
        $this->paid('both', ['slug' => 'acme-both', 'name' => 'Both Ways']);
        $this->paid('subscription', ['slug' => 'acme-sub', 'name' => 'Sub Only']);
        $this->paid('individual', ['slug' => 'acme-one', 'name' => 'Bought Only']);

        $this->get('/')->assertOk()->assertSee('Included in subscription')->assertSee('USD 19.00 / month')->assertSee('/subscription');
        $this->get('/?tier=subscription')->assertOk()->assertSee('Both Ways')->assertSee('Sub Only')->assertDontSee('Bought Only');

        $this->get('/item/acme-both')->assertOk()->assertSee('Included in subscription')->assertSee('USD 49.00')->assertSee('Buy now')->assertSee('Subscribe · USD 19.00 / month');
        $this->get('/item/acme-sub')->assertOk()->assertSee('Available with the monthly subscription')->assertDontSee('Buy now');
        $this->get('/item/acme-one')->assertOk()->assertDontSee('Subscribe ·')->assertSee('One-time purchase in USD.')->assertSee('Buy now');
        $this->get('/subscription')->assertOk()->assertSee('Modrix Cloud Pro')->assertSee('Both Ways')->assertSee('Sub Only')->assertDontSee('Bought Only');
    }

    public function test_nothing_is_offered_while_the_subscription_is_off()
    {
        Setting::put('subscription_enabled', false);
        $this->paid('both', ['slug' => 'acme-both']);
        Http::fake();

        $this->get('/item/acme-both')->assertOk()->assertDontSee('Included in subscription')->assertDontSee('Subscribe ·');
        $this->actingAs($this->customer)->post('/subscribe')->assertSessionHasErrors('subscription');
        Http::assertNothingSent();
    }

    // Checkout

    public function test_subscribe_opens_a_stripe_checkout_in_subscription_mode()
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_sub_9', 'url' => 'https://checkout.stripe.com/c/pay/cs_sub_9'])]);

        $this->actingAs($this->customer)->post('/subscribe')->assertRedirect('https://checkout.stripe.com/c/pay/cs_sub_9');

        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame('pending', $subscription->status);
        $this->assertSame('cs_sub_9', $subscription->stripe_session);
        $this->assertSame('19.00', $subscription->amount);
        Http::assertSent(fn ($request) => $request['mode'] === 'subscription'
            && $request['customer_email'] === $this->customer->email
            && $request['line_items'][0]['price_data']['unit_amount'] === 1900
            && $request['line_items'][0]['price_data']['currency'] === 'usd'
            && $request['line_items'][0]['price_data']['recurring']['interval'] === 'month'
            && $request['subscription_data']['metadata']['subscription_id'] === (string) $subscription->id);
    }

    public function test_subscribe_uses_the_stripe_price_when_one_is_set()
    {
        Setting::put('subscription_stripe_price', 'price_123abc');
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_sub_9', 'url' => 'https://checkout.stripe.com/c/pay/cs_sub_9'])]);

        $this->actingAs($this->customer)->post('/subscribe')->assertRedirect('https://checkout.stripe.com/c/pay/cs_sub_9');

        Http::assertSent(fn ($request) => $request['line_items'][0]['price'] === 'price_123abc' && ! isset($request['line_items'][0]['price_data']));
    }

    public function test_subscribers_cannot_subscribe_twice()
    {
        $this->subscribe();
        Http::fake();

        $this->actingAs($this->customer)->post('/subscribe')->assertRedirect('/account')->assertSessionHasErrors('subscription');
        Http::assertNothingSent();
    }

    // Webhook

    public function test_the_webhook_activates_updates_and_cancels_the_subscription()
    {
        $subscription = $this->subscribe();

        $this->assertSame('active', $subscription->status);
        $this->assertSame('sub_'.$this->customer->id, $subscription->stripe_subscription);
        $this->assertSame('cus_'.$this->customer->id, $subscription->stripe_customer);

        $this->deliver(['id' => 'evt_up', 'type' => 'customer.subscription.updated', 'data' => ['object' => $this->stripeSubscription($this->customer, 'active', ['cancel_at_period_end' => true])]])->assertOk();
        $subscription->refresh();
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertSame(1893456000, $subscription->current_period_end->getTimestamp());

        $this->deliver(['id' => 'evt_fail', 'type' => 'invoice.payment_failed', 'data' => ['object' => ['id' => 'in_1', 'subscription' => 'sub_'.$this->customer->id]]])->assertOk();
        $this->assertSame('past_due', $subscription->fresh()->status);
        $this->assertTrue($subscription->fresh()->isLive(), 'Access continues while Stripe retries the payment.');

        $this->cancel($this->customer);
        $subscription->refresh();
        $this->assertSame('canceled', $subscription->status);
        $this->assertNotNull($subscription->ended_at);

        // A late "updated" event does not bring a canceled subscription back.
        $this->deliver(['id' => 'evt_late', 'type' => 'customer.subscription.updated', 'data' => ['object' => $this->stripeSubscription($this->customer, 'active')]])->assertOk();
        $this->assertSame('canceled', $subscription->fresh()->status);
    }

    public function test_subscription_events_before_checkout_completed_find_the_subscription()
    {
        $subscription = Subscription::query()->create(['user_id' => $this->customer->id, 'status' => 'pending', 'amount' => '19.00', 'currency' => 'USD', 'stripe_session' => 'cs_early']);

        $this->deliver(['id' => 'evt_early', 'type' => 'customer.subscription.updated', 'data' => ['object' => $this->stripeSubscription($this->customer, 'active', ['metadata' => ['subscription_id' => (string) $subscription->id]])]])->assertOk();

        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertSame('sub_'.$this->customer->id, $subscription->fresh()->stripe_subscription);
    }

    public function test_an_unpaid_or_expired_checkout_gives_no_access()
    {
        $subscription = Subscription::query()->create(['user_id' => $this->customer->id, 'status' => 'pending', 'amount' => '19.00', 'currency' => 'USD', 'stripe_session' => 'cs_x']);

        $this->deliver(['id' => 'evt_u', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_x', 'mode' => 'subscription', 'payment_status' => 'unpaid']]])->assertOk();
        $this->assertSame('pending', $subscription->fresh()->status);

        $this->deliver(['id' => 'evt_x', 'type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_x', 'mode' => 'subscription']]])->assertOk();
        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertSame(0, Order::query()->count());
    }

    // Licenses

    public function test_subscribers_get_a_license_for_included_packages()
    {
        $included = $this->paid('both', ['slug' => 'acme-both', 'name' => 'Both Ways']);
        $this->paid('individual', ['slug' => 'acme-one']);

        // Not subscribed yet.
        $this->actingAs($this->customer)->post('/account/licenses', ['product' => 'acme-both'])->assertSessionHasErrors('product');

        $this->subscribe();
        $this->actingAs($this->customer)->get('/account')->assertOk()->assertSee('Monthly subscription')->assertSee('Included in your subscription')->assertSee('Cancel subscription');
        $this->post('/account/licenses', ['product' => 'acme-both'])->assertRedirect('/account');

        $license = License::query()->where('package_id', $included->id)->firstOrFail();
        $this->assertSame('subscription', $license->type);
        $this->assertSame('active', $license->status);

        // Asking again returns the same key: one key per customer and package.
        $this->post('/account/licenses', ['product' => 'acme-both']);
        $this->assertSame(1, License::query()->count());

        // Packages only sold individually are not part of it.
        $this->post('/account/licenses', ['product' => 'acme-one'])->assertSessionHasErrors('product');
        $this->get('/item/acme-both')->assertSee('Included in your subscription.');
    }

    public function test_after_cancellation_the_api_refuses_subscription_licenses_but_purchases_keep_working()
    {
        $viaSubscription = $this->paid('both', ['slug' => 'acme-both', 'identifier' => 'acme/both']);
        $bought = $this->paid('both', ['slug' => 'acme-bought', 'identifier' => 'acme/bought']);
        $release = PackageRelease::query()->create(['package_id' => $viaSubscription->id, 'version' => '1.0.0', 'platform_version' => '^1.0', 'path' => 'releases/x.zip', 'sha256' => str_repeat('a', 64), 'size' => 10, 'signed_metadata' => [], 'signature' => 'x', 'published_at' => now()]);

        $this->subscribe();
        $this->actingAs($this->customer)->post('/account/licenses', ['product' => 'acme-both']);
        $subLicense = License::query()->where('package_id', $viaSubscription->id)->firstOrFail();

        // Bought individually while subscribed: a purchase license.
        Order::query()->create(['user_id' => $this->customer->id, 'package_id' => $bought->id, 'amount' => '49.00', 'currency' => 'USD', 'stripe_session' => 'cs_buy', 'status' => 'pending']);
        $this->deliver(['id' => 'evt_buy', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_buy', 'mode' => 'payment', 'payment_status' => 'paid', 'payment_intent' => 'pi_buy']]])->assertOk();
        $paidLicense = License::query()->where('package_id', $bought->id)->firstOrFail();
        $this->assertSame('paid', $paidLicense->type);

        $this->activate($subLicense);
        $this->activate($paidLicense);
        $this->postJson('/api/marketplace/v1/licenses/check', ['key' => $subLicense->key, 'instance_id' => 'store-a'])
            ->assertOk()->assertJsonPath('token.status', 'active')->assertJsonPath('token.kind', 'subscription');

        $this->cancel($this->customer);

        $this->assertSame('expired', $subLicense->fresh()->status);
        $this->postJson('/api/marketplace/v1/licenses/check', ['key' => $subLicense->key, 'instance_id' => 'store-a'])
            ->assertOk()->assertJsonPath('token.status', 'expired')->assertJsonPath('token.activated', true);
        $this->postJson('/api/marketplace/v1/updates', ['key' => $subLicense->key, 'instance_id' => 'store-a'])
            ->assertOk()->assertJsonPath('available', false);
        $this->postJson('/api/marketplace/v1/downloads', ['key' => $subLicense->key, 'instance_id' => 'store-a', 'release_id' => $release->id])
            ->assertForbidden()->assertJsonPath('error', 'license_inactive');
        $this->postJson('/api/marketplace/v1/licenses/activate', ['key' => $subLicense->key, 'instance_id' => 'store-b'])->assertForbidden();

        // The purchase is not affected.
        $this->assertSame('active', $paidLicense->fresh()->status);
        $this->postJson('/api/marketplace/v1/licenses/check', ['key' => $paidLicense->key, 'instance_id' => 'store-a'])
            ->assertOk()->assertJsonPath('token.status', 'active')->assertJsonPath('token.kind', 'paid');

        $this->actingAs($this->customer)->get('/account')->assertSee('Subscription ended');
        $this->post('/account/licenses', ['product' => 'acme-both'])->assertSessionHasErrors(['product' => 'This key came with your subscription, which has ended. Subscribe again or buy the package to use it.']);
    }

    public function test_resubscribing_reactivates_the_same_key()
    {
        $package = $this->paid('subscription', ['slug' => 'acme-sub']);
        $this->subscribe();
        $this->actingAs($this->customer)->post('/account/licenses', ['product' => 'acme-sub']);
        $license = License::query()->firstOrFail();
        $this->activate($license);

        $this->cancel($this->customer);
        $this->assertSame('expired', $license->fresh()->status);

        // A new subscription (new Stripe subscription) brings the key back, on the same store.
        Subscription::query()->create(['user_id' => $this->customer->id, 'status' => 'pending', 'amount' => '19.00', 'currency' => 'USD', 'stripe_session' => 'cs_again']);
        $this->deliver(['id' => 'evt_again', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_again', 'mode' => 'subscription', 'payment_status' => 'paid', 'subscription' => 'sub_again', 'customer' => 'cus_'.$this->customer->id]]])->assertOk();

        $this->assertSame('active', $license->fresh()->status);
        $this->assertSame(1, License::query()->where('package_id', $package->id)->count());
        $this->assertSame(1, LicenseActivation::query()->where('license_id', $license->id)->where('active', true)->count());
    }

    public function test_removing_a_package_from_the_subscription_expires_its_subscription_licenses()
    {
        $package = $this->paid('both', ['slug' => 'acme-both']);
        $this->subscribe();
        $this->actingAs($this->customer)->post('/account/licenses', ['product' => 'acme-both']);
        $license = License::query()->firstOrFail();

        $this->actingAs($this->admin())->post('/admin/catalog', ['prices' => [$package->id => ['tier' => 'premium', 'price' => '49', 'sale' => 'individual']]]);
        $this->assertSame('expired', $license->fresh()->status);

        $this->post('/admin/catalog', ['prices' => [$package->id => ['tier' => 'premium', 'price' => '49', 'sale' => 'both']]]);
        $this->assertSame('active', $license->fresh()->status);
    }

    public function test_buying_a_package_held_through_the_subscription_makes_it_permanent()
    {
        $package = $this->paid('both', ['slug' => 'acme-both']);
        $this->subscribe();
        $this->actingAs($this->customer)->post('/account/licenses', ['product' => 'acme-both']);
        $license = License::query()->firstOrFail();
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_keep', 'url' => 'https://checkout.stripe.com/c/pay/cs_keep'])]);

        $this->post('/buy/acme-both')->assertRedirect('https://checkout.stripe.com/c/pay/cs_keep');
        $this->deliver(['id' => 'evt_keep', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_keep', 'mode' => 'payment', 'payment_status' => 'paid', 'payment_intent' => 'pi_keep']]])->assertOk();

        $license->refresh();
        $this->assertSame('paid', $license->type);
        $this->cancel($this->customer);
        $this->assertSame('active', $license->fresh()->status);
        $this->assertSame(1, License::query()->where('package_id', $package->id)->count());
    }

    public function test_a_revoked_subscription_license_stays_revoked()
    {
        $this->paid('both', ['slug' => 'acme-both']);
        $this->subscribe();
        $this->actingAs($this->customer)->post('/account/licenses', ['product' => 'acme-both']);
        $license = License::query()->firstOrFail();
        $license->forceFill(['status' => 'revoked'])->save();

        $this->post('/account/licenses', ['product' => 'acme-both'])->assertSessionHasErrors('product');
        $this->cancel($this->customer);
        $this->assertSame('revoked', $license->fresh()->status);
    }

    // Account

    public function test_account_cancel_and_billing_portal()
    {
        $this->subscribe();
        Http::fake([
            'api.stripe.com/v1/subscriptions/*' => Http::response(['id' => 'sub_'.$this->customer->id, 'cancel_at_period_end' => true]),
            'api.stripe.com/v1/billing_portal/sessions' => Http::response(['id' => 'bps_1', 'url' => 'https://billing.stripe.com/p/session/bps_1']),
        ]);

        $this->actingAs($this->customer)->post('/account/subscription/cancel')->assertRedirect('/account')->assertSessionHas('notice');
        $this->assertTrue(Subscription::query()->firstOrFail()->cancel_at_period_end);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/subscriptions/sub_'.$this->customer->id) && $request['cancel_at_period_end'] === 'true');
        // Still active until Stripe ends it.
        $this->assertTrue(Subscription::query()->firstOrFail()->isLive());

        $this->post('/account/subscription/portal')->assertRedirect('https://billing.stripe.com/p/session/bps_1');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/billing_portal/sessions') && $request['customer'] === 'cus_'.$this->customer->id);
    }

    public function test_the_account_offers_the_subscription()
    {
        $this->actingAs($this->customer)->get('/account')->assertOk()->assertSee('Subscribe for USD 19.00 / month');
    }

    // Admin

    public function test_admin_saves_the_subscription_plan()
    {
        Setting::put('subscription_enabled', false);
        $this->actingAs($this->admin());

        $this->post('/admin/subscription-plan', ['subscription_enabled' => '1', 'subscription_name' => 'Pro', 'subscription_price' => '0', 'subscription_currency' => 'eur'])
            ->assertSessionHasErrors('subscription_price');
        $this->post('/admin/subscription-plan', ['subscription_enabled' => '1', 'subscription_name' => 'Pro', 'subscription_price' => '25', 'subscription_currency' => 'eur', 'subscription_stripe_price' => 'not-a-price'])
            ->assertSessionHasErrors('subscription_stripe_price');
        $this->post('/admin/subscription-plan', ['subscription_enabled' => '1', 'subscription_name' => 'Pro', 'subscription_price' => '25', 'subscription_currency' => 'eur', 'subscription_stripe_price' => 'price_abc123'])
            ->assertRedirect('/admin/catalog');

        $this->assertTrue(Setting::get('subscription_enabled'));
        $this->assertSame('25.00', Setting::get('subscription_price'));
        $this->assertSame('EUR', Setting::get('subscription_currency'));
        $this->assertSame('price_abc123', Setting::get('subscription_stripe_price'));
        $this->get('/admin/catalog')->assertOk()->assertSee('value="price_abc123"', false);

        $this->post('/admin/subscription-plan', ['subscription_enabled' => '0', 'subscription_name' => 'Pro', 'subscription_price' => '25', 'subscription_currency' => 'EUR']);
        $this->assertFalse(Setting::get('subscription_enabled'));

        auth()->logout();
        $this->actingAs($this->customer)->post('/admin/subscription-plan', ['subscription_enabled' => '1', 'subscription_name' => 'X', 'subscription_price' => '1', 'subscription_currency' => 'USD'])->assertForbidden();
    }

    public function test_admin_lists_subscriptions_orders_and_licenses_with_their_source()
    {
        $this->paid('both', ['slug' => 'acme-both', 'name' => 'Both Ways']);
        $this->subscribe();
        $other = $this->customer();
        $this->subscribe($other);
        $this->cancel($other);
        $this->actingAs($this->customer)->post('/account/licenses', ['product' => 'acme-both']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/subscriptions')->assertOk()
            ->assertSee($this->customer->email)->assertSee($other->email)
            ->assertSee('status-active', false)->assertSee('status-canceled', false);
        $this->get('/admin/subscriptions?status=canceled')->assertOk()->assertSee($other->email)->assertDontSee($this->customer->email);
        $this->get('/admin/orders')->assertOk()->assertSee('Monthly subscription')->assertSee($this->customer->email);
        $this->get('/admin/licenses')->assertOk()->assertSee('Subscription · active');
        $this->get('/admin')->assertOk()->assertSee('Active subscriptions');
        $this->get('/admin/general?tab=payments')->assertOk()->assertSee('customer.subscription.deleted');
    }
}
