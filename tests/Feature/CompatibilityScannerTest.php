<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Services\CompatibilityReport;
use Marketplace\Services\CompatibilityScanner;
use Marketplace\Services\CoreReleases;
use Tests\Support\Fixtures;
use Tests\TestCase;
use ZipArchive;

class CompatibilityScannerTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    private const PROVIDER = <<<'PHP'
<?php
namespace Acme\Slots;
class SlotsServiceProvider
{
    public function boot($slots): void
    {
        $slots->block(self::MODULE, 'picker', 'Picker', 'Picker', [
            '%s',
        ], '%s', fn () => []);
        $slots->register('order.confirmation', self::MODULE, 'Summary', fn () => []);
    }
}
PHP;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.storage' => sys_get_temp_dir().'/compat-'.uniqid()]);
    }

    /** A module ZIP with a provider registering a block in $place. */
    private function module(array $manifest = [], string $place = 'checkout.after_summary', array $files = []): string
    {
        $manifest = array_merge([
            'name' => 'acme/slots', 'display_name' => 'Slots', 'version' => '1.0.0', 'type' => 'module',
            'platform_version' => '^1.0',
            'autoload' => ['psr-4' => ['Acme\\Slots\\' => 'src/']],
            'provider' => 'Acme\\Slots\\SlotsServiceProvider',
            'capabilities' => ['storefront.slots'],
        ], $manifest);

        return $this->zip(array_merge([
            'module.json' => json_encode($manifest),
            'src/SlotsServiceProvider.php' => sprintf(self::PROVIDER, $place, $place),
        ], $files));
    }

    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'scan').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    private function scan(string $zip, string $type = 'module', ?string $form = null): CompatibilityReport
    {
        return app(CompatibilityScanner::class)->scan($zip, $type, $form);
    }

    public function test_a_compatible_module_passes(): void
    {
        $report = $this->scan($this->module());

        $this->assertTrue($report->passed(), implode("\n", $report->errors()));
        $this->assertSame([], $report->warnings());
        $this->assertSame('^1.0', $report->platformVersion);
        $this->assertSame('1.0.0-rc.1', $report->minimumCore);
    }

    public function test_a_place_from_a_newer_core_needs_a_matching_platform_version(): void
    {
        $report = $this->scan($this->module([], 'checkout.after_shipping'));

        $this->assertFalse($report->passed());
        $this->assertSame('1.0.0-rc.3', $report->minimumCore);
        $this->assertStringContainsString('lets stores on core 1.0.0-rc.1 install this package', $report->errors()[0]);
        $this->assertStringContainsString('Set platform_version to "^1.0.0-rc.3"', $report->errors()[0]);
        $this->assertArrayHasKey('place "checkout.after_shipping"', $report->uses);

        $fixed = $this->scan($this->module(['platform_version' => '^1.0.0-rc.3'], 'checkout.after_shipping'));
        $this->assertTrue($fixed->passed(), implode("\n", $fixed->errors()));
        $this->assertSame('1.0.0-rc.3', $fixed->minimumCore);
    }

    public function test_unknown_places_slots_capabilities_and_keys_are_refused(): void
    {
        $report = $this->scan($this->module(['capabilities' => ['storefront.slots', 'teleport'], 'colour' => 'red'], 'checkout.nowhere'));
        $errors = implode("\n", $report->errors());

        $this->assertStringContainsString('"checkout.nowhere" as a block place, but that place cannot hold blocks', $errors);
        $this->assertStringContainsString('Unknown capability "teleport"', $errors);
        $this->assertStringContainsString('the key "colour"', $errors);

        $slot = $this->scan($this->module([], 'checkout.after_summary', [
            'src/Extra.php' => "<?php \$slots->register('checkout.sidebar', 'acme/slots', 'X', fn () => []);",
        ]));
        $this->assertStringContainsString('registers the storefront slot "checkout.sidebar"', implode("\n", $slot->errors()));
    }

    public function test_missing_required_fields_and_files_are_refused(): void
    {
        $report = $this->scan($this->module([
            'display_name' => '',
            'type' => 'theme',
            'routes' => ['admin' => 'routes/admin.php'],
            'migrations' => 'database/migrations',
            'provider' => 'Acme\\Slots\\Missing',
        ]));
        $errors = implode("\n", $report->errors());

        $this->assertStringContainsString('must set "display_name"', $errors);
        $this->assertStringContainsString('must set "type": "module"', $errors);
        $this->assertStringContainsString('routes.admin points to routes/admin.php', $errors);
        $this->assertStringContainsString('migrations points to database/migrations/', $errors);
        $this->assertStringContainsString('should be in src/Missing.php', $errors);
    }

    public function test_an_invalid_platform_version_or_a_different_form_value_is_refused(): void
    {
        $this->assertStringContainsString('not a valid version constraint', implode("\n", $this->scan($this->module(['platform_version' => 'banana']))->errors()));
        $this->assertStringContainsString('differs from the package', implode("\n", $this->scan($this->module(), 'module', '^2.0')->errors()));
        $this->assertTrue($this->scan($this->module(), 'module', '^1.0')->passed());
    }

    public function test_themes_are_checked_too(): void
    {
        $theme = fn (array $extra = []) => $this->zip(['theme.json' => json_encode(array_merge([
            'name' => 'acme/look', 'display_name' => 'Look', 'version' => '1.0.0', 'type' => 'theme',
            'theme_type' => 'storefront', 'platform_version' => '^1.0', 'capabilities' => ['sections'],
        ], $extra))]);

        $this->assertTrue($this->scan($theme(), 'theme')->passed());

        $bad = $this->scan($theme(['theme_type' => 'admin', 'capabilities' => ['flying'], 'screenshot' => 'shot.png']), 'theme');
        $this->assertStringContainsString('theme_type', implode("\n", $bad->errors()));
        $this->assertStringContainsString('Unknown capability "flying"', implode("\n", $bad->errors()));
        $this->assertStringContainsString('screenshot points to shot.png', implode("\n", $bad->warnings()));
    }

    public function test_required_packages_missing_from_the_marketplace_are_noted(): void
    {
        $this->package(['identifier' => 'acme/base', 'slug' => 'acme-base', 'version' => '1.0.0']);

        $report = $this->scan($this->module(['requires' => ['acme/base' => '^2.0', 'acme/gone' => '^1.0']]));

        $this->assertTrue($report->passed());
        $this->assertSame(['acme/base' => '^2.0', 'acme/gone' => '^1.0'], $report->requires);
        $warnings = implode("\n", $report->warnings());
        $this->assertStringContainsString('Requires acme/base ^2.0, but the marketplace has only 1.0.0', $warnings);
        $this->assertStringContainsString('Requires acme/gone, which is not in the marketplace', $warnings);
    }

    public function test_it_reports_the_first_published_core_that_can_run_the_package(): void
    {
        $cores = app(CoreReleases::class);
        $cores->publish($cores->upload($this->coreZip('1.0.0-rc.2')->getRealPath(), '1.0.0-rc.2', null, '8.3'));
        $zip = $this->module(['platform_version' => '^1.0.0-rc.3'], 'checkout.after_shipping');

        $waiting = $this->scan($zip);
        $this->assertTrue($waiting->passed());
        $this->assertNull($waiting->firstCore);
        $this->assertSame('1.0.0-rc.2', $waiting->latestCore);
        $this->assertStringContainsString('No published core release can run this package yet (it needs core 1.0.0-rc.3 or later)', implode("\n", $waiting->warnings()));

        $cores->publish($cores->upload($this->coreZip('1.0.0-rc.3')->getRealPath(), '1.0.0-rc.3', null, '8.3'));
        $ready = $this->scan($zip);
        $this->assertSame('1.0.0-rc.3', $ready->firstCore);
        $this->assertSame([], $ready->warnings());
    }

    public function test_bundled_catalog_packages_pass_the_scan(): void
    {
        foreach (['module' => 'catalog/modules/*/*/module.json', 'theme' => 'catalog/themes/*/*/theme.json'] as $type => $glob) {
            foreach (glob(base_path($glob)) as $manifest) {
                $dir = dirname($manifest);
                $files = [];

                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    $relative = strtr(substr($file->getPathname(), strlen($dir) + 1), [DIRECTORY_SEPARATOR => '/']);
                    $files[$relative] = (string) file_get_contents($file->getPathname());
                }

                $report = $this->scan($this->zip($files), $type);
                $this->assertTrue($report->passed(), basename($dir).': '.implode("\n", [...$report->errors(), ...$report->malicious()]));
                $this->assertSame([], $report->suspicious(), basename($dir));
            }
        }
    }
}
