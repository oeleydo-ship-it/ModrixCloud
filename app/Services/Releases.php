<?php

namespace Marketplace\Services;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\File;
use Marketplace\Models\Package;
use Marketplace\Models\PackageRelease;
use Throwable;

/**
 * Stores package versions as signed releases and picks the update a store
 * should install.
 */
class Releases
{
    public function __construct(private readonly SigningKey $key) {}

    /**
     * Copies the ZIP into private storage and signs its metadata.
     */
    public function publish(Package $package, string $zipPath, string $version, string $platformVersion): PackageRelease
    {
        $relative = 'releases/'.str_replace('/', '-', $package->identifier).'/'.$version.'.zip';
        $target = rtrim((string) config('marketplace.storage'), '/\\').'/'.$relative;
        File::ensureDirectoryExists(dirname($target));
        File::copy($zipPath, $target);

        $release = PackageRelease::query()->updateOrCreate(
            ['package_id' => $package->id, 'version' => $version],
            [
                'path' => $relative,
                'sha256' => (string) hash_file('sha256', $target),
                'size' => (int) filesize($target),
                'platform_version' => $platformVersion,
                'signed_metadata' => [],
                'signature' => '',
                'published_at' => now(),
            ],
        );

        $release->setRelation('package', $package);
        $this->setPlatformVersion($release, $platformVersion);
        $this->syncListing($package);

        return $release;
    }

    /**
     * Keeps the listing's version on the newest release. Publishing an older
     * version (the bundled catalog lagging behind an uploaded update) must not
     * make the repository show a lower version than stores are offered.
     */
    public function syncListing(Package $package): void
    {
        $newest = $package->unsetRelation('releases')->latestRelease();

        if ($newest !== null && $package->version !== $newest->version) {
            $package->forceFill(['version' => $newest->version, 'platform_version' => $newest->platform_version])->save();
        }
    }

    /**
     * The newest release above the installed version that the store's
     * platform version does NOT satisfy: an update waiting on a core update.
     */
    public function newestNeedingCore(Package $package, ?string $installed, string $platform): ?PackageRelease
    {
        $best = null;

        foreach ($package->releases()->get() as $release) {
            if (($installed !== null && $installed !== '' && ! $this->greater($release->version, $installed)) || $this->satisfies($platform, $release->platform_version)) {
                continue;
            }

            if ($best === null || $this->greater($release->version, $best->version)) {
                $best = $release;
            }
        }

        return $best;
    }

    /**
     * Changes the core constraint of a published release and signs it again,
     * e.g. when a release turns out to need a newer core than it declared.
     */
    public function setPlatformVersion(PackageRelease $release, string $platformVersion): void
    {
        $package = $release->package;
        $release->forceFill(['platform_version' => $platformVersion])->save();
        $signed = $this->key->sign([
            'extension' => $package->identifier,
            'type' => $package->type,
            'product' => $package->slug,
            'version' => $release->version,
            'sha256' => $release->sha256,
            'size' => $release->size,
            'platform_version' => $platformVersion,
            'release_id' => $release->id,
        ]);
        $release->forceFill(['signed_metadata' => $signed['data'], 'signature' => $signed['signature']])->save();
    }

    /**
     * The newest release above the installed version that the store's
     * platform version satisfies.
     */
    public function newestFor(Package $package, ?string $installed, ?string $platform): ?PackageRelease
    {
        $best = null;

        foreach ($package->releases()->get() as $release) {
            if ($installed !== null && $installed !== '' && ! $this->greater($release->version, $installed)) {
                continue;
            }

            if ($platform !== null && $platform !== '' && ! $this->satisfies($platform, $release->platform_version)) {
                continue;
            }

            if ($best === null || $this->greater($release->version, $best->version)) {
                $best = $release;
            }
        }

        return $best;
    }

    /** Absolute path of a release ZIP. */
    public function path(PackageRelease $release): string
    {
        return rtrim((string) config('marketplace.storage'), '/\\').'/'.$release->path;
    }

    /**
     * The release as the store receives it.
     *
     * @return array<string, mixed>
     */
    public function payload(PackageRelease $release): array
    {
        return [
            'id' => $release->id,
            'version' => $release->version,
            'platform_version' => $release->platform_version,
            'published_at' => $release->published_at?->toIso8601String(),
            'verification' => ['metadata' => $release->signed_metadata, 'signature' => $release->signature],
        ];
    }

    private function greater(string $a, string $b): bool
    {
        try {
            return Comparator::greaterThan($a, $b);
        } catch (Throwable) {
            return false;
        }
    }

    private function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable) {
            return false;
        }
    }
}
