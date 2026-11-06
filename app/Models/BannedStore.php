<?php

namespace Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A store that may not connect: matched by its installation id or its
 * domain (so reinstalling the platform on the same domain does not escape it).
 *
 * @property int $id
 * @property string|null $instance_id
 * @property string|null $domain
 * @property string|null $reason
 * @property int|null $banned_by
 */
class BannedStore extends Model
{
    protected $guarded = ['*'];

    public static function blocks(?string $instanceId, ?string $domain): bool
    {
        $domain = self::host($domain);

        if (($instanceId === null || $instanceId === '') && $domain === '') {
            return false;
        }

        return self::query()->where(function ($query) use ($instanceId, $domain) {
            $query->when($instanceId !== null && $instanceId !== '', fn ($q) => $q->orWhere('instance_id', $instanceId))
                ->when($domain !== '', fn ($q) => $q->orWhere('domain', $domain));
        })->exists();
    }

    /** "Shop.Example:8017" → "shop.example": bans match the host, whatever the port. */
    public static function host(?string $domain): string
    {
        return strtolower(explode(':', trim((string) $domain))[0]);
    }
}
