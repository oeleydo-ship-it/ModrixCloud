<?php

namespace Marketplace\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Checks and stores uploads in private storage. Package ZIPs are inspected
 * but never extracted or executed here; images must be real PNG/JPEG/WebP.
 */
class Uploads
{
    public const MAX_ZIP_BYTES = 20 * 1024 * 1024;

    public const MAX_UNPACKED_BYTES = 100 * 1024 * 1024;

    public const MAX_IMAGE_BYTES = 3 * 1024 * 1024;

    /**
     * A package ZIP whose root manifest names this identifier and version.
     * Returns the stored path relative to private storage.
     *
     * @throws ValidationException on the given field
     */
    public function packageZip(UploadedFile $file, string $identifier, string $type, string $version, string $field = 'package'): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);

        if (! $file->isValid() || $file->getSize() > self::MAX_ZIP_BYTES) {
            $fail('Upload a ZIP up to 20 MB.');
        }

        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath()) !== true) {
            $fail('The package is not a valid ZIP archive.');
        }

        try {
            if ($zip->numFiles > 10000) {
                $fail('The package contains too many files.');
            }

            $bytes = 0;
            $names = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) $entry['name']);
                $bytes += (int) $entry['size'];
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                $symlink = (($attributes >> 16) & 0170000) === 0120000;

                if ($symlink || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)|^[a-zA-Z]:#', $name) === 1 || $bytes > self::MAX_UNPACKED_BYTES) {
                    $fail('The package contains unsafe paths or is larger than 100 MB unpacked.');
                }

                $names[$i] = $name;
            }

            $manifestName = $type === 'theme' ? 'theme.json' : 'module.json';
            $prefix = $this->rootFolder($names, $manifestName);
            $index = $prefix === null ? false : array_search($prefix.$manifestName, $names, true);
            $stat = $index === false ? false : $zip->statIndex($index);

            if ($stat === false || $stat['size'] > 65536) {
                $fail("Include {$manifestName} at the ZIP root or inside a single top folder (up to 64 KB).");
            }

            $manifest = json_decode((string) $zip->getFromIndex($index), true);

            if (! is_array($manifest) || ($manifest['name'] ?? null) !== $identifier || ($manifest['version'] ?? null) !== $version) {
                $fail('The ZIP manifest package identifier and version must match the listing.');
            }

            if ($prefix !== '') {
                return $this->storeUnwrapped($zip, $names, $prefix);
            }
        } finally {
            $zip->close();
        }

        return $this->store($file, 'packages', 'zip');
    }

    /**
     * A PNG, JPEG or WebP image up to 3 MB and 8000 × 8000 pixels (no SVG).
     *
     * @throws ValidationException on the given field
     */
    public function image(UploadedFile $file, string $folder, string $field): string
    {
        $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
        $info = $file->isValid() && $file->getSize() <= self::MAX_IMAGE_BYTES ? @getimagesize($file->getRealPath()) : false;

        if (! is_array($info) || ! isset($types[$info['mime']]) || $info[0] > 8000 || $info[1] > 8000) {
            throw ValidationException::withMessages([$field => 'Use a PNG, JPEG or WebP image up to 3 MB and 8000 × 8000 pixels.']);
        }

        return $this->store($file, $folder, $types[$info['mime']]);
    }

    /**
     * '' when the manifest is at the ZIP root, 'folder/' when every file sits in
     * one top folder holding the manifest (a zipped package folder), else null.
     * macOS metadata (__MACOSX/) is ignored.
     *
     * @param  array<int, string>  $names
     */
    private function rootFolder(array $names, string $manifestName): ?string
    {
        if (in_array($manifestName, $names, true)) {
            return '';
        }

        $folders = [];

        foreach ($names as $name) {
            if (str_starts_with($name, '__MACOSX/')) {
                continue;
            }

            if (! str_contains($name, '/')) {
                return null;
            }

            $folders[strstr($name, '/', true)] = true;
        }

        if (count($folders) !== 1) {
            return null;
        }

        $prefix = array_key_first($folders).'/';

        return in_array($prefix.$manifestName, $names, true) ? $prefix : null;
    }

    /**
     * Rewrites a ZIP whose files sit in one top folder so the manifest is at
     * the root, as stores expect, and stores the result.
     *
     * @param  array<int, string>  $names
     */
    private function storeUnwrapped(ZipArchive $source, array $names, string $prefix): string
    {
        $relative = 'packages/'.Str::random(32).'.zip';
        $target = $this->path($relative);
        File::ensureDirectoryExists(dirname($target));
        $zip = new ZipArchive;
        $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($names as $i => $name) {
            $inner = substr($name, strlen($prefix));

            if (! str_starts_with($name, $prefix) || $inner === '' || str_ends_with($name, '/')) {
                continue;
            }

            $zip->addFromString($inner, (string) $source->getFromIndex($i));
        }

        $zip->close();

        return $relative;
    }

    /**
     * The largest upload PHP accepts on this server, in bytes: the smaller of
     * upload_max_filesize and post_max_size (0 means no limit). Null if both
     * are unlimited.
     */
    public static function serverLimit(): ?int
    {
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ], fn (int $bytes) => $bytes > 0);

        return $limits === [] ? null : min($limits);
    }

    /** The server limit as "8 MB", or null when unlimited. */
    public static function serverLimitLabel(): ?string
    {
        $bytes = self::serverLimit();

        return $bytes === null ? null : rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.').' MB';
    }

    /** "8M", "1G", "512K" or plain bytes as bytes. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }

    /** Absolute path of a stored private file. */
    public function path(string $relative): string
    {
        return rtrim((string) config('marketplace.storage'), '/\\').'/'.ltrim($relative, '/');
    }

    private function store(UploadedFile $file, string $folder, string $extension): string
    {
        $relative = $folder.'/'.Str::random(32).'.'.$extension;
        File::ensureDirectoryExists(dirname($this->path($relative)));
        File::copy($file->getRealPath(), $this->path($relative));

        return $relative;
    }
}
