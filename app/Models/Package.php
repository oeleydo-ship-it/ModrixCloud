<?php

namespace Marketplace\Models;

use Composer\Semver\Comparator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A theme or extension listed in the marketplace.
 *
 * @property int $id
 * @property string $identifier vendor/name
 * @property string $type theme|module
 * @property string $slug
 * @property string $name
 * @property string $summary
 * @property string $price
 * @property string $currency
 * @property bool $is_free
 * @property bool $sold_individually paid packages: can be bought on their own (/buy/{slug})
 * @property bool $in_subscription paid packages: included in the monthly subscription
 * @property string $status draft|published|hidden
 * @property bool $featured
 * @property string|null $demo_url
 * @property string|null $thumbnail_path
 * @property list<string>|null $features
 */
class Package extends Model
{
    protected $fillable = [
        'identifier', 'type', 'slug', 'name', 'summary', 'description', 'publisher_id', 'price', 'currency',
        'is_free', 'status', 'featured', 'demo_url', 'repository_url', 'thumbnail_path', 'features',
        'author', 'license_name', 'version', 'platform_version', 'changelog', 'sold_individually', 'in_subscription',
    ];

    protected function casts(): array
    {
        return ['is_free' => 'boolean', 'sold_individually' => 'boolean', 'in_subscription' => 'boolean', 'featured' => 'boolean', 'features' => 'array', 'price' => 'decimal:2'];
    }

    /** A paid package customers can buy on its own. */
    public function buyable(): bool
    {
        return ! $this->is_free && $this->sold_individually !== false;
    }

    /** A paid package that comes with the monthly subscription. */
    public function inSubscription(): bool
    {
        return ! $this->is_free && $this->in_subscription === true;
    }

    /** individual | subscription | both, for the admin forms. */
    public function saleMode(): string
    {
        $individual = $this->sold_individually !== false;

        return $this->in_subscription === true ? ($individual ? 'both' : 'subscription') : 'individual';
    }

    public function releases(): HasMany
    {
        return $this->hasMany(PackageRelease::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publisher_id');
    }

    /** The highest version (semantic version order, not publish date). */
    public function latestRelease(): ?PackageRelease
    {
        // Uses eager-loaded releases when present (catalog pages load them for every package).
        $releases = $this->relationLoaded('releases') ? $this->releases : $this->releases()->get();

        return $releases->reduce(
            fn (?PackageRelease $best, PackageRelease $r) => $best === null || Comparator::greaterThan($r->version, $best->version) ? $r : $best,
        );
    }
}
