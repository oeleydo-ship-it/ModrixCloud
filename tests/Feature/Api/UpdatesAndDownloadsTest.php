<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Marketplace\Models\CoreRelease;
use Marketplace\Models\License;
use Marketplace\Models\Package;
use Marketplace\Models\User;
use Marketplace\Services\Licensing;
use Marketplace\Services\Releases;
use Marketplace\Services\Signing;
use Marketplace\Services\SigningKey;
use Tests\TestCase;
use ZipArchive;

/**
 * POST updates / downloads and the one-time download link, as the store
 * platform's PackageInstaller uses them.
 */
class UpdatesAndDownloadsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/marketplace/v1/';

    private Package $package;

    private License $license;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = Package::query()->create(['identifier' => 'acme/reviews', 'type' => 'module', 'slug' => 'acme-reviews', 'name' => 'Reviews', 'status' => 'published']);
        $this->license = app(Licensing::class)->issue(User::factory()->create(), $this->package, 'free');
        $this->postJson(self::API.'licenses/activate', ['key' => $this->license->key, 'instance_id' => 'store-a'])->assertOk();
    }

    private function zip(string $version): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pkg').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('module.json', json_encode(['name' => 'acme/reviews', 'version' => $version]));
        $zip->addFromString('src/Provider.php', '<?php // '.$version);
        $zip->close();

        return $path;
    }

    private function publish(string $version, string $platform = '^1.0', ?Package $package = null)
    {
        return app(Releases::class)->publish($package ?? $this->package, $this->zip($version), $version, $platform);
    }

    private function update(?string $installed, string $platform = '1.2.0'): array
    {
        return $this->postJson(self::API.'updates', ['key' => $this->license->key, 'instance_id' => 'store-a', 'installed_version' => $installed, 'platform_version' => $platform])->assertOk()->json();
    }

    public function test_updates_offer_the_newest_compatible_signed_release()
    {
        $this->publish('1.0.0');
        $newest = $this->publish('1.4.0');
        $this->publish('2.0.0', '^2.0');

        $body = $this->update('1.0.0');

        $this->assertTrue($body['available']);
        $this->assertSame($newest->id, $body['release']['id']);
        $this->assertSame('1.4.0', $body['release']['version']);
        $metadata = $body['release']['verification']['metadata'];
        $this->assertTrue(Signing::verify($metadata, $body['release']['verification']['signature'], [app(SigningKey::class)->publicKey()]));
        $this->assertSame('acme/reviews', $metadata['extension']);
        $this->assertSame('module', $metadata['type']);
        $this->assertSame('acme-reviews', $metadata['product']);
        $this->assertSame($newest->size, $metadata['size']);
        $this->assertIsInt($metadata['size']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $metadata['sha256']);
        $this->assertSame('^1.0', $metadata['platform_version']);
    }

    private function core(string $version): void
    {
        CoreRelease::query()->create(['version' => $version, 'min_php' => '8.3', 'path' => "core/{$version}.zip", 'sha256' => str_repeat('a', 64), 'size' => 1, 'status' => CoreRelease::PUBLISHED, 'signed_metadata' => [], 'signature' => '', 'published_at' => now()]);
    }

    public function test_an_update_for_a_newer_core_names_the_core_to_install()
    {
        $this->publish('1.0.0');
        $this->publish('2.0.0', '>=1.0.0-rc.2');
        $this->core('1.0.0-rc.1');
        $this->core('1.0.0-rc.2');
        $this->core('1.0.0');

        $reply = $this->update('1.0.0', '1.0.0-rc.1');

        $this->assertFalse($reply['available']);
        $this->assertSame(['version' => '2.0.0', 'required' => '>=1.0.0-rc.2', 'current' => '1.0.0-rc.1', 'minimum_core' => '1.0.0-rc.2', 'latest_core' => '1.0.0'], $reply['core_update']);
        $this->assertStringContainsString('Update the core to 1.0.0-rc.2 or later first.', $reply['reason']);

        $this->assertTrue($this->update('1.0.0', '1.0.0-rc.2')['available']);
    }

    public function test_an_update_for_an_unreleased_core_says_none_is_available_yet()
    {
        $this->publish('2.0.0', '^2.0');
        $this->core('1.0.0');

        $reply = $this->update(null, '1.0.0');

        $this->assertNull($reply['core_update']['minimum_core']);
        $this->assertSame('1.0.0', $reply['core_update']['latest_core']);
        $this->assertStringContainsString('not available yet', $reply['reason']);

        $this->get('/item/acme-reviews')->assertOk()->assertSee('Requires core')->assertSee('which is not released yet (latest core: 1.0.0)');
        $this->getJson(self::API.'catalog')->assertJsonPath('products.0.platform_version', '^2.0')->assertJsonPath('products.0.minimum_core', null);
    }

    public function test_correcting_a_release_core_requirement_re_signs_it()
    {
        $release = $this->publish('2.0.0');
        app(Releases::class)->setPlatformVersion($release, '>=1.0.0-rc.2');

        $release->refresh();
        $this->assertSame('>=1.0.0-rc.2', $release->platform_version);
        $this->assertSame('>=1.0.0-rc.2', $release->signed_metadata['platform_version']);
        $this->assertTrue(Signing::verify($release->signed_metadata, $release->signature, [app(SigningKey::class)->publicKey()]));
        $this->assertFalse($this->update(null, '1.0.0-rc.1')['available']);
    }

    public function test_admins_correct_the_core_requirement_from_the_package_form()
    {
        $this->publish('2.0.0');
        $this->package->update(['version' => '2.0.0', 'platform_version' => '^1.0', 'summary' => 'Stars']);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->post('/admin/repository/acme-reviews', [
            'name' => 'Reviews', 'summary' => 'Stars', 'tier' => 'free', 'price' => '0', 'status' => 'published',
            'version' => '2.0.0', 'platform_version' => '>=1.0.0-rc.2',
        ])->assertRedirect('/admin/repository/acme-reviews');

        $this->assertSame('>=1.0.0-rc.2', $this->package->releases()->value('platform_version'));
        $this->assertFalse($this->update(null, '1.0.0-rc.1')['available']);
    }

    public function test_nothing_newer_means_no_update()
    {
        $this->publish('1.4.0');

        $body = $this->update('1.4.0');

        $this->assertFalse($body['available']);
        $this->assertNull($body['release']);
        $this->assertIsString($body['reason']);
    }

    public function test_a_download_link_streams_the_signed_bytes_once()
    {
        $release = $this->publish('1.4.0');

        $body = $this->postJson(self::API.'downloads', ['key' => $this->license->key, 'instance_id' => 'store-a', 'release_id' => $release->id])->assertOk()->json();

        $this->assertStringStartsWith(config('app.url').'/', $body['url']);
        $this->assertSame($release->id, $body['release']['id']);
        $path = parse_url($body['url'], PHP_URL_PATH);
        $response = $this->get($path)->assertOk();
        $this->assertSame($release->size, strlen($response->streamedContent()));
        $this->assertSame($release->sha256, hash('sha256', $response->streamedContent()));

        $this->get($path)->assertStatus(410);
    }

    public function test_a_missing_file_does_not_use_up_the_link_and_names_the_package()
    {
        $release = $this->publish('1.1.0');
        $url = $this->postJson(self::API.'downloads', ['key' => $this->license->key, 'instance_id' => 'store-a', 'release_id' => $release->id])->json('url');
        $path = app(Releases::class)->path($release);
        rename($path, $path.'.away');

        $this->get($url)->assertNotFound();

        rename($path.'.away', $path);
        $this->get($url)->assertOk()->assertDownload('acme-reviews-1.1.0.zip');
    }

    public function test_a_license_revoked_after_the_link_was_made_cannot_download()
    {
        $release = $this->publish('1.1.0');
        $url = $this->postJson(self::API.'downloads', ['key' => $this->license->key, 'instance_id' => 'store-a', 'release_id' => $release->id])->json('url');
        $this->license->forceFill(['status' => 'revoked'])->save();

        $this->get($url)->assertStatus(403);
    }

    public function test_expired_links_are_refused()
    {
        $release = $this->publish('1.4.0');
        $url = $this->postJson(self::API.'downloads', ['key' => $this->license->key, 'instance_id' => 'store-a', 'release_id' => $release->id])->json('url');

        Carbon::setTestNow(now()->addMinutes(11));

        $this->get(parse_url($url, PHP_URL_PATH))->assertStatus(410);
    }

    public function test_downloads_need_this_package_and_an_activated_store()
    {
        $other = Package::query()->create(['identifier' => 'acme/other', 'type' => 'module', 'slug' => 'acme-other', 'name' => 'Other', 'status' => 'published']);
        $foreign = $this->publish('1.0.0', '^1.0', $other);
        $mine = $this->publish('1.0.0');

        $this->postJson(self::API.'downloads', ['key' => $this->license->key, 'instance_id' => 'store-a', 'release_id' => $foreign->id])
            ->assertStatus(404)->assertJson(['error' => 'release_not_found']);
        $this->postJson(self::API.'downloads', ['key' => $this->license->key, 'instance_id' => 'store-b', 'release_id' => $mine->id])
            ->assertStatus(403)->assertJson(['error' => 'not_activated']);
        $this->get('/api/marketplace/v1/download/not-a-real-token')->assertStatus(410);
    }
}
