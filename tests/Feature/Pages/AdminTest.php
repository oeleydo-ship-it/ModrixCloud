<?php

namespace Tests\Feature\Pages;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Marketplace\Models\Category;
use Marketplace\Models\License;
use Marketplace\Models\LicenseActivation;
use Marketplace\Models\Package;
use Marketplace\Models\Setting;
use Marketplace\Models\User;
use Marketplace\Services\Licensing;
use Marketplace\Services\SigningKey;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** The admin workspace: setup, sign-in, repository, pricing, settings, licenses. */
class AdminTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    public function test_setup_creates_the_first_admin_only_once()
    {
        $this->get('/admin/setup')->assertOk();
        $this->post('/admin/setup', ['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Long-enough-pass-1'])->assertRedirect('/admin');
        $this->assertTrue(User::query()->where('email', 'owner@example.test')->value('is_admin'));

        auth()->logout();
        $this->get('/admin/setup')->assertRedirect('/admin/login');
        $this->post('/admin/setup', ['name' => 'X', 'email' => 'x@example.test', 'password' => 'Long-enough-pass-1'])->assertRedirect('/admin/login');
        $this->assertSame(1, User::query()->where('is_admin', true)->count());
    }

    public function test_admin_pages_need_an_admin()
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->actingAs($this->customer())->get('/admin')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin')->assertOk()->assertSee('Repository');
    }

    public function test_admin_sign_in_refuses_customers()
    {
        User::factory()->create(['email' => 'cust@example.test', 'password' => 'Secret-pass-1']);
        User::factory()->admin()->create(['email' => 'boss@example.test', 'password' => 'Secret-pass-1']);

        $this->post('/admin/login', ['email' => 'cust@example.test', 'password' => 'Secret-pass-1'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/admin/login', ['email' => 'boss@example.test', 'password' => 'Secret-pass-1'])->assertRedirect('/admin');
    }

    public function test_the_repository_shows_30_packages_a_page_with_plain_page_links()
    {
        $admin = $this->admin();

        for ($i = 1; $i <= 35; $i++) {
            $this->package(['name' => sprintf('Pkg %02d', $i)]);
        }

        $this->actingAs($admin)->get('/admin/repository')->assertOk()
            ->assertSee('Pkg 30')->assertDontSee('Pkg 31')
            ->assertSee('class="pager"', false)
            ->assertSee('Showing 1–30 of 35')
            ->assertSee('rel="next"', false)
            ->assertDontSee('<svg', false);

        $this->get('/admin/repository?page=2')->assertOk()
            ->assertSee('Pkg 35')->assertDontSee('Pkg 30')
            ->assertSee('rel="prev"', false);
    }

    public function test_deleting_a_package_removes_it_with_its_releases_licenses_and_files()
    {
        $admin = $this->admin();
        $package = $this->package(['identifier' => 'acme/gone', 'slug' => 'acme-gone', 'name' => 'Gone']);
        $zip = $this->packageZip('acme/gone', '1.0.0');
        $release = app(\Marketplace\Services\Releases::class)->publish($package, $zip->getRealPath(), '1.0.0', '^1.0');
        $file = app(\Marketplace\Services\Releases::class)->path($release);
        License::query()->create(['user_id' => $this->customer()->id, 'package_id' => $package->id, 'key' => 'MRX-GGGGG-GGGGG-GGGGG-GGGGG', 'type' => 'free', 'status' => 'active']);
        $this->assertFileExists($file);

        $this->actingAs($admin)->get('/admin/repository')->assertSee('/admin/repository/acme-gone/delete', false);
        $this->get('/admin/repository/acme-gone')->assertSee('Delete package')->assertSee('1 license key');

        $this->post('/admin/repository/acme-gone/delete')
            ->assertRedirect('/admin/repository')
            ->assertSessionHas('notice', 'Gone (acme/gone) was deleted.');

        $this->assertNull(Package::query()->find($package->id));
        $this->assertSame(0, License::query()->count());
        $this->assertSame(0, \Marketplace\Models\PackageRelease::query()->count());
        $this->assertFileDoesNotExist($file);
    }

    public function test_a_package_with_orders_cannot_be_deleted_and_customers_cannot_delete()
    {
        $package = $this->package(['slug' => 'acme-sold', 'is_free' => false, 'price' => '9.00']);
        $buyer = $this->customer();
        \Marketplace\Models\Order::query()->create(['user_id' => $buyer->id, 'package_id' => $package->id, 'amount' => '9.00', 'currency' => 'USD', 'status' => 'paid']);

        $this->actingAs($buyer)->post('/admin/repository/acme-sold/delete');
        $this->assertNotNull($package->fresh());

        $this->actingAs($this->admin())->get('/admin/repository/acme-sold')->assertSee('has orders, so it cannot be deleted');
        $this->post('/admin/repository/acme-sold/delete')->assertSessionHasErrors('delete');
        $this->assertNotNull($package->fresh());
    }

    public function test_editing_a_package_and_adding_one()
    {
        $admin = $this->admin();
        $category = Category::query()->create(['slug' => 'marketing', 'name' => 'Marketing', 'type' => 'module']);
        $package = $this->package(['identifier' => 'acme/labels', 'slug' => 'acme-labels']);

        $this->actingAs($admin)->get('/admin/repository')->assertOk()->assertSee('acme/labels');
        $this->actingAs($admin)->post('/admin/repository/acme-labels', [
            'name' => 'Renamed', 'summary' => 'New text', 'tier' => 'premium', 'price' => '19.00', 'status' => 'hidden',
            'version' => '1.0.0', 'platform_version' => '^1.0', 'author' => 'Acme', 'license_name' => 'MIT',
            'categories' => [$category->slug], 'features_text' => "Fast\nSmall", 'featured' => '1',
        ])->assertRedirect('/admin/repository/acme-labels');

        $package->refresh();
        $this->assertSame('Renamed', $package->name);
        $this->assertSame('19.00', $package->price);
        $this->assertFalse($package->is_free);
        $this->assertSame('hidden', $package->status);
        $this->assertSame(['Fast', 'Small'], $package->features);
        $this->assertTrue($package->featured);
        $this->assertSame(['marketing'], $package->categories->pluck('slug')->all());

        $this->actingAs($admin)->post('/admin/repository/new', [
            'identifier' => 'acme/new-one', 'type' => 'theme', 'name' => 'New One', 'summary' => 'Fresh', 'tier' => 'free', 'price' => '0',
            'status' => 'published', 'version' => '1.0.0', 'platform_version' => '^1.0', 'package' => $this->packageZip('acme/new-one', '1.0.0', 'theme'),
        ])->assertRedirect('/admin/repository/acme-new-one');
        $this->assertSame('1.0.0', Package::query()->where('identifier', 'acme/new-one')->firstOrFail()->latestRelease()?->version);
    }

    public function test_thumbnails_must_be_raster_images()
    {
        $this->package(['slug' => 'acme-labels']);
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->actingAs($this->admin())->post('/admin/repository/acme-labels', [
            'name' => 'X', 'summary' => 'Y', 'tier' => 'free', 'price' => '0', 'status' => 'published', 'version' => '1.0.0', 'thumbnail' => $svg,
        ])->assertSessionHasErrors('thumbnail');

        $this->actingAs($this->admin())->post('/admin/repository/acme-labels', [
            'name' => 'X', 'summary' => 'Y', 'tier' => 'free', 'price' => '0', 'status' => 'published', 'version' => '1.0.0',
            'thumbnail' => UploadedFile::fake()->image('thumb.png', 600, 400),
        ])->assertSessionHasNoErrors();
        $this->get('/media/acme-labels')->assertOk()->assertHeader('content-type', 'image/png');
    }

    public function test_settings_keep_secrets_and_never_show_them()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/stripe', ['stripe_publishable' => 'pk_test_1', 'stripe_secret' => 'sk_test_secret_value', 'stripe_webhook_secret' => 'whsec_secret_value'])->assertRedirect();
        $this->actingAs($admin)->post('/admin/stripe', ['stripe_publishable' => 'pk_test_2', 'stripe_secret' => '', 'stripe_webhook_secret' => ''])->assertRedirect();

        $this->assertSame('sk_test_secret_value', Setting::get('stripe_secret'));
        $this->assertSame('pk_test_2', Setting::get('stripe_publishable'));
        $page = $this->actingAs($admin)->get('/admin/general?tab=payments')->assertOk()->getContent();
        $this->assertStringNotContainsString('sk_test_secret_value', $page);
        $this->assertStringNotContainsString('whsec_secret_value', $page);
        $this->assertStringNotContainsString('sk_test_secret_value', (string) DB::table('settings')->where('key', 'stripe_secret')->value('value'));

        $this->actingAs($admin)->get('/admin/general?tab=licensing')->assertSee(app(SigningKey::class)->publicKey());
    }

    public function test_admins_revoke_licenses_and_release_activations()
    {
        $admin = $this->admin();
        $license = app(Licensing::class)->issue($this->customer(), $this->package(), 'free');
        LicenseActivation::query()->create(['license_id' => $license->id, 'instance_id' => 'store-a', 'domain' => 'shop.example', 'active' => true, 'activated_at' => now()]);

        $this->actingAs($admin)->get('/admin/licenses?q='.$license->key)->assertOk()->assertSee('shop.example');
        $this->actingAs($admin)->post('/admin/licenses/'.$license->id.'/release')->assertRedirect();
        $this->assertNull($license->activeActivation());
        $this->actingAs($admin)->post('/admin/licenses/'.$license->id.'/revoke')->assertRedirect();
        $this->assertSame('revoked', License::query()->find($license->id)->status);
    }
}
