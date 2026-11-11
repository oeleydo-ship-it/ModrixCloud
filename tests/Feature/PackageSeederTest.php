<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Marketplace\Models\Package;
use Marketplace\Models\User;
use Tests\TestCase;

/** `marketplace:publish --all` and the seeder publish every package in the source tree. */
class PackageSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/mk-all-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->root.'/modules/modrixcommerce/razorpay');
        File::ensureDirectoryExists($this->root.'/themes/modrixcommerce/pawzy');
        File::put($this->root.'/modules/modrixcommerce/razorpay/module.json', json_encode(['name' => 'modrixcommerce/razorpay', 'version' => '1.0.0', 'display_name' => 'Razorpay']));
        File::put($this->root.'/themes/modrixcommerce/pawzy/theme.json', json_encode(['name' => 'modrixcommerce/pawzy', 'version' => '1.0.0', 'display_name' => 'Pawzy']));
        config(['marketplace.source_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_publish_all_publishes_every_package_once()
    {
        $this->artisan('marketplace:publish', ['--all' => true])->assertSuccessful();
        $this->artisan('marketplace:publish', ['--all' => true])->assertSuccessful();

        $this->assertSame(['module', 'theme'], Package::query()->orderBy('type')->pluck('type')->all());
        $this->assertSame(1, Package::query()->where('identifier', 'modrixcommerce/razorpay')->firstOrFail()->releases()->count());
        $this->assertSame('Pawzy', Package::query()->where('identifier', 'modrixcommerce/pawzy')->firstOrFail()->name);
    }

    public function test_seeding_publishes_packages_and_applies_listings()
    {
        $this->seed(DatabaseSeeder::class);

        $razorpay = Package::query()->where('identifier', 'modrixcommerce/razorpay')->firstOrFail();
        $this->assertSame('1.0.0', $razorpay->latestRelease()?->version);
        $this->assertSame(['payments'], $razorpay->categories()->pluck('slug')->all());
        $this->assertNotEmpty($razorpay->features);
    }

    public function test_admins_publish_bundled_packages_from_the_repository_page()
    {
        $admin = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password-1']);
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);

        $this->get('/admin/repository')->assertOk()->assertSee('2 are not published yet')->assertSee('Publish bundled packages');

        $this->post('/admin/repository/bundled')->assertRedirect('/admin/repository');

        $this->assertSame(2, Package::query()->count());
        $this->get('/admin/repository')->assertSee('All of them are published.')->assertDontSee('Publish bundled packages');
    }

    public function test_bundled_catalog_manifests_are_publishable()
    {
        $manifests = array_merge(glob(base_path('catalog/modules/*/*/module.json')), glob(base_path('catalog/themes/*/*/theme.json')));
        $this->assertNotEmpty($manifests);

        foreach ($manifests as $file) {
            $manifest = json_decode((string) file_get_contents($file), true);
            $dir = dirname($file);
            $this->assertSame(basename(dirname($dir)).'/'.basename($dir), $manifest['name'] ?? null, $file);
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) ($manifest['version'] ?? ''), $file);
        }
    }
}
