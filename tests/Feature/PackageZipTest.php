<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Marketplace\Services\Uploads;
use Tests\TestCase;
use ZipArchive;

/** Package ZIPs may hold the manifest at the root or inside one zipped package folder. */
class PackageZipTest extends TestCase
{
    /** @param  array<string, string>  $files */
    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($files as $name => $contents) {
            str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $contents);
        }

        $zip->close();

        return new UploadedFile($path, 'package.zip', 'application/zip', null, true);
    }

    private function manifest(): string
    {
        return json_encode(['name' => 'modrixcommerce/delivery-slots', 'version' => '2.0.0']);
    }

    public function test_a_zipped_package_folder_is_accepted_and_stored_with_the_manifest_at_the_root()
    {
        $uploads = app(Uploads::class);
        $stored = $uploads->packageZip($this->zip([
            'delivery-slots/' => '',
            'delivery-slots/module.json' => $this->manifest(),
            'delivery-slots/src/Provider.php' => '<?php',
            '__MACOSX/delivery-slots/._module.json' => 'x',
        ]), 'modrixcommerce/delivery-slots', 'module', '2.0.0');

        $zip = new ZipArchive;
        $zip->open($uploads->path($stored));
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertSame($this->manifest(), $zip->getFromName('module.json'));
        $zip->close();
        sort($names);
        $this->assertSame(['module.json', 'src/Provider.php'], $names);
    }

    public function test_a_manifest_at_the_root_is_stored_as_uploaded()
    {
        $stored = app(Uploads::class)->packageZip($this->zip(['module.json' => $this->manifest()]), 'modrixcommerce/delivery-slots', 'module', '2.0.0');

        $this->assertFileExists(app(Uploads::class)->path($stored));
    }

    public function test_a_manifest_outside_a_single_top_folder_is_rejected()
    {
        $this->expectException(ValidationException::class);

        app(Uploads::class)->packageZip($this->zip([
            'one/module.json' => $this->manifest(),
            'two/readme.txt' => 'x',
        ]), 'modrixcommerce/delivery-slots', 'module', '2.0.0');
    }
}
