<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use Marketplace\Models\Package;
use Marketplace\Models\User;
use ZipArchive;

/** Builds packages, people and package ZIPs for page tests. */
trait Fixtures
{
    protected function package(array $attributes = []): Package
    {
        static $n = 0;
        $n++;

        return Package::query()->create(array_merge([
            'identifier' => "acme/pkg-{$n}",
            'type' => 'module',
            'slug' => "acme-pkg-{$n}",
            'name' => "Package {$n}",
            'summary' => 'A useful package',
            'status' => 'published',
            'is_free' => true,
            'price' => '0',
            'currency' => 'USD',
            'author' => 'Acme',
        ], $attributes));
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function customer(): User
    {
        return User::factory()->create();
    }

    /** A package ZIP with its manifest at the root. */
    protected function packageZip(string $identifier, string $version, string $type = 'module', array $extra = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString($type === 'theme' ? 'theme.json' : 'module.json', json_encode(['name' => $identifier, 'version' => $version]));

        foreach ($extra as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return new UploadedFile($path, 'package.zip', 'application/zip', null, true);
    }

    /** A core platform release ZIP: artisan, vendor/autoload.php and app/Core/Platform.php declaring the version. */
    protected function coreZip(string $version, array $extra = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('artisan', '<?php');
        $zip->addFromString('vendor/autoload.php', '<?php');
        $zip->addFromString('app/Core/Platform.php', "<?php

class Platform
{
    public const VERSION = '{$version}';
}
");

        foreach ($extra as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return new UploadedFile($path, 'core.zip', 'application/zip', null, true);
    }
}
