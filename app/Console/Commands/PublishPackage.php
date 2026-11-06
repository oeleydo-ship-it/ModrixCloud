<?php

namespace Marketplace\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Marketplace\Http\Controllers\PublishController;
use Marketplace\Models\Package;
use Marketplace\Services\Releases;
use Marketplace\Services\Submissions;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Publishes a theme or extension from a source tree (modules/vendor/name or
 * themes/vendor/name) as a signed release. A new package is listed as
 * published; an existing listing keeps its admin-edited details.
 */
class PublishPackage extends Command
{
    protected $signature = 'marketplace:publish {identifier? : vendor/package} {--all : Publish every module and theme in the source tree, skipping versions already released} {--root= : Source tree with modules/ and themes/ (default CATALOG_ROOT)}';

    protected $description = 'Publish a package version from the source tree';

    private const SKIP = ['.git', 'node_modules', '.DS_Store'];

    public function handle(Releases $releases): int
    {
        $root = rtrim((string) ($this->option('root') ?: config('marketplace.source_root')), '/\\');

        if ($this->option('all')) {
            return $this->publishAll($releases, $root);
        }

        $identifier = (string) $this->argument('identifier');

        return $this->publishOne($releases, $root, $identifier);
    }

    /** Every modules/vendor/name and themes/vendor/name under the root. */
    private function publishAll(Releases $releases, string $root): int
    {
        $identifiers = [];

        foreach (['module' => 'modules', 'theme' => 'themes'] as $type => $folder) {
            foreach (glob($root.'/'.$folder.'/*/*/'.$type.'.json') ?: [] as $manifest) {
                $dir = dirname($manifest);
                $identifiers[] = basename(dirname($dir)).'/'.basename($dir);
            }
        }

        if ($identifiers === []) {
            $this->error("No modules or themes found under {$root}.");

            return self::FAILURE;
        }

        $failed = 0;

        foreach (array_unique($identifiers) as $identifier) {
            if ($this->publishOne($releases, $root, $identifier, skipExisting: true) !== self::SUCCESS) {
                $failed++;
            }
        }

        $this->line(count($identifiers).' packages checked, '.$failed.' failed.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function publishOne(Releases $releases, string $root, string $identifier, bool $skipExisting = false): int
    {
        if (preg_match('#^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$#D', $identifier) !== 1) {
            $this->error('Use a vendor/package identifier, for example modrixcommerce/razorpay.');

            return self::FAILURE;
        }

        [$type, $dir] = $this->locate($root, $identifier);

        if ($dir === null) {
            $this->error("No modules/{$identifier}/module.json or themes/{$identifier}/theme.json under {$root}.");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($dir.'/'.($type === 'theme' ? 'theme.json' : 'module.json')), true);
        $version = is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';

        if (! is_array($manifest) || ($manifest['name'] ?? null) !== $identifier || preg_match(PublishController::VERSION, $version) !== 1) {
            $this->error('The manifest must name this package and have a version such as 1.0.0.');

            return self::FAILURE;
        }

        $platform = (string) ($manifest['requires']['platform'] ?? $manifest['platform_version'] ?? '^1.0');

        if ($skipExisting && Package::query()->where('identifier', $identifier)->whereHas('releases', fn ($q) => $q->where('version', $version))->exists()) {
            $this->line("{$identifier} {$version} is already published.");

            return self::SUCCESS;
        }

        $zip = $this->zip($dir);

        try {
            $release = DB::transaction(function () use ($identifier, $type, $manifest, $version, $platform, $releases, $zip) {
                $package = Package::query()->firstOrNew(['identifier' => $identifier]);

                if (! $package->exists) {
                    $package->fill([
                        'type' => $type,
                        'slug' => Submissions::slugFor($identifier),
                        'name' => (string) ($manifest['title'] ?? $manifest['display_name'] ?? $identifier),
                        'summary' => mb_substr((string) ($manifest['description'] ?? ''), 0, 250),
                        'author' => mb_substr((string) ($manifest['author'] ?? ''), 0, 120),
                        'license_name' => (string) ($manifest['license'] ?? 'MIT'),
                        'currency' => (string) config('marketplace.currency'),
                        'status' => 'published',
                    ]);
                }

                $package->fill(['version' => $version, 'platform_version' => $platform])->save();

                return $releases->publish($package, $zip, $version, $platform);
            });
        } finally {
            @unlink($zip);
        }

        $this->info("Published {$identifier} {$release->version} ({$release->size} bytes, sha256 {$release->sha256}).");

        return self::SUCCESS;
    }

    /** @return array{0: string|null, 1: string|null} */
    private function locate(string $root, string $identifier): array
    {
        foreach (['module' => 'modules', 'theme' => 'themes'] as $type => $folder) {
            $dir = $root.'/'.$folder.'/'.$identifier;

            if (is_file($dir.'/'.$type.'.json')) {
                return [$type, $dir];
            }
        }

        return [null, null];
    }

    /** The package directory as a ZIP with the manifest at its root. */
    private function zip(string $dir): string
    {
        $base = tempnam(sys_get_temp_dir(), 'pkg');
        @unlink($base); // Only the name is needed; the archive gets a .zip suffix.
        $path = $base.'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));

            if ($file->isLink() || array_intersect(explode('/', $relative), self::SKIP) !== []) {
                continue;
            }

            $zip->addFile($file->getPathname(), $relative);
        }

        $zip->close();

        return $path;
    }
}
