<?php

namespace Marketplace\Services;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Marketplace\Models\CoreRelease;
use Throwable;
use ZipArchive;

/**
 * Stores core platform versions as signed releases and lists the ones a
 * store can update to.
 */
class CoreReleases
{
    private const MAX_FILES = 50000;

    public function __construct(private readonly SigningKey $key) {}

    /**
     * Validates the ZIP and stores it as a draft.
     *
     * @throws CoreReleaseException
     */
    public function upload(string $zipPath, string $version, ?string $notes, string $minPhp): CoreRelease
    {
        $version = trim($version);
        $minPhp = trim($minPhp);

        // Strict semver: also keeps path separators and "dev-*" names out of the storage path.
        $valid = preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version) === 1;

        try {
            (new VersionParser)->normalize($version);
        } catch (Throwable) {
            $valid = false;
        }

        if (! $valid) {
            throw new CoreReleaseException('Enter a valid version such as 1.1.0.');
        }

        if (preg_match('/^\d+\.\d+(\.\d+)?$/', $minPhp) !== 1) {
            throw new CoreReleaseException('Enter the minimum PHP version as 8.3 or 8.3.0.');
        }

        if (CoreRelease::query()->where('version', $version)->exists()) {
            throw new CoreReleaseException("Version {$version} already exists.");
        }

        $this->inspect($zipPath, $version);

        $relative = 'core/'.$version.'.zip';
        $target = rtrim((string) config('marketplace.storage'), '/\\').'/'.$relative;
        File::ensureDirectoryExists(dirname($target));
        File::copy($zipPath, $target);

        return CoreRelease::query()->create([
            'version' => $version,
            'notes' => $notes,
            'min_php' => $minPhp,
            'path' => $relative,
            'sha256' => (string) hash_file('sha256', $target),
            'size' => (int) filesize($target),
            'status' => CoreRelease::DRAFT,
        ]);
    }

    /**
     * Re-checks and re-hashes the stored file, signs its metadata and lists
     * the release. Only drafts can be published.
     *
     * @throws CoreReleaseException
     */
    public function publish(CoreRelease $release): CoreRelease
    {
        if ($release->status !== CoreRelease::DRAFT) {
            throw new CoreReleaseException('Only draft releases can be published.');
        }

        $file = $this->path($release);

        if (! is_file($file)) {
            throw new CoreReleaseException('The release file is missing from storage.');
        }

        // The stored file is what stores install: check it again, not only the upload.
        $this->inspect($file, $release->version);

        $release->sha256 = (string) hash_file('sha256', $file);
        $release->size = (int) filesize($file);

        $signed = $this->key->sign([
            'kind' => 'core',
            'release_id' => $release->id,
            'version' => $release->version,
            'min_php' => $release->min_php,
            'sha256' => $release->sha256,
            'size' => $release->size,
        ]);

        $release->forceFill([
            'status' => CoreRelease::PUBLISHED,
            'signed_metadata' => $signed['data'],
            'signature' => $signed['signature'],
            'published_at' => now(),
        ])->save();

        return $release;
    }

    /**
     * Stops listing and serving the release (a draft or a published one).
     *
     * @throws CoreReleaseException
     */
    public function withdraw(CoreRelease $release): void
    {
        if (! in_array($release->status, [CoreRelease::DRAFT, CoreRelease::PUBLISHED], true)) {
            throw new CoreReleaseException('Only draft or published releases can be withdrawn.');
        }

        $release->forceFill(['status' => CoreRelease::WITHDRAWN])->save();
    }

    /**
     * Published releases above the installed version that the store's PHP
     * can run, newest first.
     *
     * @return Collection<int, CoreRelease>
     */
    public function newerThan(?string $current, ?string $php): Collection
    {
        return CoreRelease::query()
            ->where('status', CoreRelease::PUBLISHED)
            ->get()
            ->filter(fn (CoreRelease $r) => ($current === null || $current === '' || $this->greater($r->version, $current))
                && ($php === null || $php === '' || version_compare($php, $r->min_php, '>=')))
            ->sort(fn (CoreRelease $a, CoreRelease $b) => $this->greater($a->version, $b->version) ? -1 : ($this->greater($b->version, $a->version) ? 1 : 0))
            ->values();
    }

    /**
     * The release as the store receives it.
     *
     * @return array<string, mixed>
     */
    public function payload(CoreRelease $release): array
    {
        return [
            'id' => $release->id,
            'version' => $release->version,
            'min_php' => $release->min_php,
            'notes' => $release->notes,
            'published_at' => $release->published_at?->toIso8601String(),
            'verification' => ['metadata' => $release->signed_metadata, 'signature' => $release->signature],
        ];
    }

    /** Absolute path of a release ZIP. */
    public function path(CoreRelease $release): string
    {
        return rtrim((string) config('marketplace.storage'), '/\\').'/'.$release->path;
    }

    /** @throws CoreReleaseException */
    private function inspect(string $zipPath, string $version): void
    {
        $limit = (int) config('marketplace.core_max_mb', 200);

        if (! is_file($zipPath) || filesize($zipPath) > $limit * 1024 * 1024) {
            throw new CoreReleaseException("Upload a ZIP up to {$limit} MB.");
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new CoreReleaseException('The file is not a valid ZIP archive.');
        }

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new CoreReleaseException('The release contains too many files.');
            }

            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                $symlink = (($attributes >> 16) & 0170000) === 0120000;

                if ($symlink || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)|^[a-zA-Z]:#', $name) === 1) {
                    throw new CoreReleaseException('The release contains unsafe paths.');
                }

                if (self::isDatabaseFile($name)) {
                    throw new CoreReleaseException('The release contains a database file ('.basename($name).'). Build it from a clean checkout.');
                }

                $names[$name] = true;
            }

            foreach (['artisan', 'vendor/autoload.php', 'app/Core/Platform.php'] as $required) {
                if (! isset($names[$required])) {
                    throw new CoreReleaseException("The release must contain {$required}.");
                }
            }

            $platform = (string) $zip->getFromName('app/Core/Platform.php');

            if (preg_match('/const\s+VERSION\s*=\s*\'([^\']+)\'/', $platform, $m) !== 1 || $m[1] !== $version) {
                throw new CoreReleaseException("app/Core/Platform.php must declare VERSION = '{$version}'.");
            }
        } finally {
            $zip->close();
        }
    }

    /** SQLite databases and their journals must never ship to stores. */
    private static function isDatabaseFile(string $name): bool
    {
        return preg_match('/\.(sqlite|sqlite3|db)$|-(journal|wal|shm)$/i', basename(rtrim($name, '/'))) === 1;
    }

    private function greater(string $a, string $b): bool
    {
        try {
            return Comparator::greaterThan($a, $b);
        } catch (Throwable) {
            return false;
        }
    }
}
