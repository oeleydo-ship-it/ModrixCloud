<?php

namespace Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's monthly subscription, kept in sync by the Stripe webhook.
 *
 * @property int $id
 * @property int $user_id
 * @property string $status pending|active|trialing|past_due|incomplete|unpaid|canceled|expired
 * @property string $amount
 * @property string $currency
 * @property string|null $stripe_session
 * @property string|null $stripe_subscription
 * @property string|null $stripe_customer
 * @property \Illuminate\Support\Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property \Illuminate\Support\Carbon|null $ended_at
 */
class Subscription extends Model
{
    /**
     * Statuses that give access to included packages. past_due keeps access
     * while Stripe retries the payment; Stripe then cancels the subscription
     * (customer.subscription.deleted) or marks it unpaid.
     */
    public const LIVE = ['active', 'trialing', 'past_due'];

    protected $fillable = ['user_id', 'status', 'amount', 'currency', 'stripe_session', 'stripe_subscription', 'stripe_customer', 'current_period_end', 'cancel_at_period_end', 'ended_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'current_period_end' => 'datetime', 'ended_at' => 'datetime', 'cancel_at_period_end' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /** @param  Builder<Subscription>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', self::LIVE);
    }
}
