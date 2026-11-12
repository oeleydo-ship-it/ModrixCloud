<?php

namespace Marketplace\Services;

use Marketplace\Models\License;
use Marketplace\Models\Package;
use Marketplace\Models\Setting;
use Marketplace\Models\Subscription;
use Marketplace\Models\User;

/**
 * The subscription, billed monthly or yearly: the plan (Admin → Pricing), who is subscribed,
 * and the licenses it gives.
 *
 * A subscriber gets a license (type "subscription") for any package included
 * in the subscription. When the subscription ends, or the package leaves the
 * subscription, those licenses become "expired", which the store API refuses;
 * a renewed subscription makes them active again. Licenses bought individually
 * (type "paid") and free ones are never touched.
 */
class Subscriptions
{
    public function __construct(private readonly Licensing $licensing) {}

    /** Billing intervals, as Stripe names them. */
    public const INTERVALS = ['month', 'year'];

    /**
     * @return array{enabled: bool, name: string, price: string, currency: string, stripe_price: string, yearly_price: string, stripe_price_yearly: string}
     */
    public function plan(): array
    {
        return [
            'enabled' => (bool) Setting::get('subscription_enabled', false),
            'name' => (string) (Setting::get('subscription_name') ?: 'Modrix Cloud subscription'),
            'price' => number_format((float) Setting::get('subscription_price', 0), 2, '.', ''),
            'currency' => strtoupper((string) (Setting::get('subscription_currency') ?: app(Catalog::class)->currency())),
            // Optional recurring Prices created in Stripe (price_…). Without one, checkout
            // sends the amount and currency above as a monthly (or yearly) price.
            'stripe_price' => (string) Setting::get('subscription_stripe_price', ''),
            // Yearly billing is offered when it has a price; 0 means monthly only.
            'yearly_price' => number_format((float) Setting::get('subscription_yearly_price', 0), 2, '.', ''),
            'stripe_price_yearly' => (string) Setting::get('subscription_stripe_price_yearly', ''),
        ];
    }

    /** New customers can subscribe: the plan is on and has a monthly price. */
    public function available(): bool
    {
        $plan = $this->plan();

        return $plan['enabled'] && (float) $plan['price'] > 0;
    }

    /**
     * The billing intervals new customers can choose: month, and year when it has a price.
     *
     * @return list<string>
     */
    public function intervals(): array
    {
        if (! $this->available()) {
            return [];
        }

        return (float) $this->plan()['yearly_price'] > 0 ? ['month', 'year'] : ['month'];
    }

    /**
     * Amount, currency and Stripe Price for one interval.
     *
     * @return array{name: string, interval: string, price: string, currency: string, stripe_price: string}
     */
    public function pricing(string $interval): array
    {
        $plan = $this->plan();
        $yearly = $interval === 'year';

        return [
            'name' => $plan['name'],
            'interval' => $yearly ? 'year' : 'month',
            'price' => $yearly ? $plan['yearly_price'] : $plan['price'],
            'currency' => $plan['currency'],
            'stripe_price' => $yearly ? $plan['stripe_price_yearly'] : $plan['stripe_price'],
        ];
    }

    /** "USD 19.00 / month" or "USD 190.00 / year". */
    public function priceLabel(string $interval = 'month'): string
    {
        $pricing = $this->pricing($interval);

        return $pricing['currency'].' '.$pricing['price'].' / '.$pricing['interval'];
    }

    /** Every offered price: "USD 19.00 / month or USD 190.00 / year". */
    public function offerLabel(): string
    {
        return implode(' or ', array_map(fn (string $i) => $this->priceLabel($i), $this->intervals() ?: ['month']));
    }

    /** Yearly saving against twelve monthly payments, in whole percent (0 when none). */
    public function yearlySaving(): int
    {
        $plan = $this->plan();
        $twelve = (float) $plan['price'] * 12;

        return $twelve > 0 && (float) $plan['yearly_price'] > 0 ? max(0, (int) floor((1 - (float) $plan['yearly_price'] / $twelve) * 100)) : 0;
    }

    /** The customer's subscription that currently gives access, if any. */
    public function current(?User $user): ?Subscription
    {
        if ($user === null) {
            return null;
        }

        return Subscription::query()->where('user_id', $user->id)->live()->latest('id')->first();
    }

    /** The newest subscription of any status (for the account page). */
    public function latest(User $user): ?Subscription
    {
        return Subscription::query()->where('user_id', $user->id)->where('status', '!=', 'pending')->latest('id')->first();
    }

    /** Whether this customer's subscription gives them this package now. */
    public function covers(?User $user, Package $package): bool
    {
        return $package->inSubscription() && $this->current($user) !== null;
    }

    /**
     * The customer's license for an included package, issued or reactivated
     * through the subscription. A license that is already active (bought or
     * free) is returned as it is; a revoked one stays revoked. Without cover,
     * returns the inactive license if there is one, else null.
     */
    public function claim(User $user, Package $package): ?License
    {
        $license = License::query()->where('user_id', $user->id)->where('package_id', $package->id)->first();

        if ($license instanceof License && $license->status === 'active') {
            return $license;
        }

        if (! $this->covers($user, $package)) {
            return $license; // Null, or the inactive license so the caller can say why.
        }

        if (! $license instanceof License) {
            return $this->licensing->issue($user, $package, 'subscription');
        }

        if ($license->status === 'revoked') {
            return $license;
        }

        // An expired subscription license, or a refunded purchase, now covered by the subscription.
        $license->forceFill(['type' => 'subscription', 'status' => 'active'])->save();

        return $license;
    }

    /** Brings the customer's subscription licenses in line with their subscription. */
    public function syncUser(User $user): void
    {
        $live = $this->current($user) !== null;

        License::query()->with('package')->where('user_id', $user->id)->where('type', 'subscription')->get()
            ->each(fn (License $license) => $this->apply($license, $live && $license->package?->inSubscription() === true));
    }

    /** After the admin changes whether a package is included. */
    public function syncPackage(Package $package): void
    {
        $included = $package->inSubscription();
        $subscribers = Subscription::query()->live()->pluck('user_id')->flip();

        License::query()->where('package_id', $package->id)->where('type', 'subscription')->get()
            ->each(fn (License $license) => $this->apply($license, $included && $subscribers->has($license->user_id)));
    }

    private function apply(License $license, bool $covered): void
    {
        if ($license->status === 'active' && ! $covered) {
            $license->forceFill(['status' => 'expired'])->save();
        } elseif ($license->status === 'expired' && $covered) {
            $license->forceFill(['status' => 'active'])->save();
        }
    }
}
