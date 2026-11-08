<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Models\CoreRelease;
use Marketplace\Models\StoreConnection;
use Marketplace\Models\User;
use Marketplace\Services\CoreReleases;
use Tests\Support\Fixtures;
use Tests\TestCase;

class CoreReleaseApiTest extends TestCase
{
    use Fixtures;
    use RefreshDatabase;

    private const API = '/api/marketplace/v1/';

    private const VERIFIER = 'a-very-long-random-code-verifier-for-pkce-tests-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.storage' => sys_get_temp_dir().'/core-api-'.uniqid()]);
    }

    private function published(string $version, string $php = '8.3'): CoreRelease
    {
        $releases = app(CoreReleases::class);

        return $releases->publish($releases->upload($this->coreZip($version)->getRealPath(), $version, 'Notes', $php));
    }

    private function connected(): string
    {
        $user = User::factory()->create();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '=');
        $location = $this->actingAs($user)->post('/connect', [
            'instance_id' => 'store-instance-1',
            'store_name' => 'Jhay Store',
            'domain' => 'localhost:8017',
            'return_url' => 'http://localhost:8017/admin/m/modrixcommerce/cloud-connector/callback',
            'state' => 'state-value-0123456789',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $this->postJson(self::API.'connections/token', ['code' => $query['code'], 'code_verifier' => self::VERIFIER, 'instance_id' => 'store-instance-1'])
            ->assertOk()->json('token');
    }

    public function test_lists_newer_published_releases(): void
    {
        $this->published('1.1.0');
        $this->published('0.9.0');

        $this->getJson(self::API.'core/releases?current=1.0.0&php=8.4.0')
            ->assertOk()
            ->assertJsonCount(1, 'releases')
            ->assertJsonPath('releases.0.version', '1.1.0')
            ->assertJsonPath('releases.0.verification.metadata.kind', 'core');
    }

    public function test_download_requires_a_connection(): void
    {
        $release = $this->published('1.1.0');

        $this->postJson(self::API.'core/downloads', ['release_id' => $release->id, 'instance_id' => 'store-instance-1'])
            ->assertStatus(401)->assertJsonPath('error', 'not_connected');

        $token = $this->connected();
        StoreConnection::query()->update(['revoked_at' => now()]);
        $this->withToken($token)->postJson(self::API.'core/downloads', ['release_id' => $release->id, 'instance_id' => 'store-instance-1'])
            ->assertStatus(401)->assertJsonPath('error', 'not_connected');
    }

    public function test_connected_store_downloads_once(): void
    {
        $release = $this->published('1.1.0');
        $token = $this->connected();

        $body = $this->withToken($token)->postJson(self::API.'core/downloads', ['release_id' => $release->id, 'instance_id' => 'store-instance-1'])
            ->assertOk()->assertJsonPath('release.version', '1.1.0')->json();
        $this->assertMatchesRegularExpression('#/api/marketplace/v1/core/download/[A-Za-z0-9]{64}$#', $body['url']);
        $this->assertNotEmpty($body['expires_at']);

        $response = $this->get($body['url'])->assertOk();
        $this->assertSame($release->sha256, hash('sha256', $response->streamedContent()));

        $this->get($body['url'])->assertStatus(410)->assertJsonPath('error', 'link_expired');
    }

    public function test_withdrawn_release_is_not_served(): void
    {
        $release = $this->published('1.1.0');
        $token = $this->connected();

        $url = $this->withToken($token)->postJson(self::API.'core/downloads', ['release_id' => $release->id, 'instance_id' => 'store-instance-1'])->json('url');
        app(CoreReleases::class)->withdraw($release);

        $this->get($url)->assertStatus(410)->assertJsonPath('error', 'link_expired');
        $this->withToken($token)->postJson(self::API.'core/downloads', ['release_id' => $release->id, 'instance_id' => 'store-instance-1'])
            ->assertStatus(404)->assertJsonPath('error', 'release_not_found');
    }

    public function test_missing_file_keeps_the_link_usable(): void
    {
        $release = $this->published('1.1.0');
        $token = $this->connected();
        $url = $this->withToken($token)->postJson(self::API.'core/downloads', ['release_id' => $release->id, 'instance_id' => 'store-instance-1'])->json('url');

        $file = app(CoreReleases::class)->path($release);
        rename($file, $file.'.bak');
        $this->get($url)->assertStatus(404)->assertJsonPath('error', 'release_not_found');
        rename($file.'.bak', $file);
        $this->get($url)->assertOk();
    }
}
