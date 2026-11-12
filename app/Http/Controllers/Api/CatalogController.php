<?php

namespace Marketplace\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marketplace\Models\License;
use Marketplace\Models\Package;
use Marketplace\Services\Catalog;
use Marketplace\Services\Connections;
use Marketplace\Services\CoreReleases;

/**
 * GET /api/marketplace/v1/catalog — published packages for the store's
 * Admin → Modrix Cloud page. With a connected store's token, each item also
 * says whether the account owns it and the state of its license.
 */
class CatalogController
{
    public function __invoke(Request $request, Connections $connections, CoreReleases $cores): JsonResponse
    {
        $connection = $connections->authenticate($request->bearerToken());
        $licenses = $connection === null ? collect() : License::query()
            ->where('user_id', $connection->user_id)
            ->with(['activations' => fn ($q) => $q->where('active', true)])
            ->get()
            ->keyBy('package_id');

        $products = Package::query()
            ->where('status', 'published')
            ->with('releases')
            ->orderByDesc('featured')
            ->orderBy('name')
            ->get()
            ->map(function (Package $p) use ($connection, $licenses, $cores) {
                $row = self::row($p, $cores);

                if ($connection !== null) {
                    /** @var License|null $license */
                    $license = $licenses->get($p->id);
                    $active = $license?->activations->first();
                    $row['owned'] = $license !== null && $license->status === 'active';
                    $row['license'] = $license === null ? null : [
                        'status' => $license->status,
                        'key_hint' => '…'.substr($license->key, -5),
                        'in_use_elsewhere' => $active !== null && $active->instance_id !== $connection->instance_id,
                    ];
                }

                return $row;
            })
            ->values();

        return response()->json(['products' => $products, 'connected' => $connection !== null]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(Package $package, ?CoreReleases $cores = null): array
    {
        return [
            'identifier' => $package->identifier,
            'type' => $package->type,
            'name' => $package->name,
            'slug' => $package->slug,
            'summary' => $package->summary,
            'price' => $package->price,
            'currency' => app(Catalog::class)->currency(),
            'is_free' => $package->is_free,
            // Paid packages: bought on their own, included in the subscription, or both.
            'sold_individually' => $package->buyable(),
            'in_subscription' => $package->inSubscription(),
            'featured' => $package->featured,
            'thumbnail_url' => $package->thumbnail_path ? url('/media/'.$package->slug) : null,
            'demo_url' => $package->demo_url,
            'repository_url' => $package->repository_url,
            'url' => url('/item/'.$package->slug),
            'latest_version' => $package->latestRelease()?->version,
            // The core the latest version needs, and the first published core release that meets it.
            'platform_version' => $platform = (string) ($package->latestRelease()?->platform_version ?? $package->platform_version),
            'minimum_core' => $platform === '' ? null : ($cores ?? app(CoreReleases::class))->firstSatisfying($platform)?->version,
            // Free packages download directly; paid ones through a license.
            'download_url' => $package->is_free ? url('/download/'.$package->slug) : null,
        ];
    }
}
