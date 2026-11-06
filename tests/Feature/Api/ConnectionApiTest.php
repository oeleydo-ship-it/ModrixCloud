<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Models\License;
use Marketplace\Models\LicenseActivation;
use Marketplace\Models\Package;
use Marketplace\Models\StoreConnection;
use Marketplace\Models\User;
use Marketplace\Services\Licensing;
use Tests\TestCase;

/**
 * Pairing a store with an account (Modrix Cloud Connector) and the
 * connected-store API.
 */
class ConnectionApiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/marketplace/v1/';

    private const VERIFIER = 'a-very-long-random-code-verifier-for-pkce-tests-1234567890';

    private function challenge(string $verifier = self::VERIFIER): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @return array<string, string> */
    private function pairing(array $overrides = []): array
    {
        return [
            'instance_id' => 'store-instance-1',
            'store_name' => 'Jhay Store',
            'domain' => 'localhost:8017',
            'return_url' => 'http://localhost:8017/admin/m/modrixcommerce/cloud-connector/callback',
            'state' => 'state-value-0123456789',
            'code_challenge' => $this->challenge(),
            'code_challenge_method' => 'S256',
            ...$overrides,
        ];
    }

    /** Approves the pairing as $user and returns the code from the redirect. */
    private function approve(User $user, array $overrides = []): string
    {
        $location = $this->actingAs($user)->post('/connect', $this->pairing($overrides))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('state-value-0123456789', $query['state']);

        return $query['code'];
    }

    /** @return array{token: string, user: User} */
    private function connected(): array
    {
        $user = User::factory()->create();
        $code = $this->approve($user);
        $token = $this->postJson(self::API.'connections/token', ['code' => $code, 'code_verifier' => self::VERIFIER, 'instance_id' => 'store-instance-1'])
            ->assertOk()->json('token');

        return ['token' => $token, 'user' => $user];
    }

    private function package(string $identifier, bool $free): Package
    {
        return Package::query()->create(['identifier' => $identifier, 'type' => 'module', 'slug' => str_replace('/', '-', $identifier), 'name' => ucfirst($identifier), 'status' => 'published', 'is_free' => $free, 'price' => $free ? '0' : '49.00']);
    }

    public function test_the_connect_page_needs_sign_in_and_shows_the_store(): void
    {
        $this->get('/connect?'.http_build_query($this->pairing()))->assertRedirect();

        $this->actingAs(User::factory()->create())->get('/connect?'.http_build_query($this->pairing()))
            ->assertOk()->assertSee('Connect Jhay Store?')->assertSee('localhost:8017');
    }

    public function test_a_return_address_outside_the_store_domain_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/connect', $this->pairing(['return_url' => 'https://evil.example/steal']))
            ->assertSessionHasErrors('return_url');
        $this->post('/connect', $this->pairing(['domain' => 'shop.example', 'return_url' => 'http://shop.example/callback']))
            ->assertSessionHasErrors('return_url');
    }

    public function test_the_code_needs_the_matching_verifier_and_works_once(): void
    {
        $user = User::factory()->create();

        $code = $this->approve($user);
        $this->postJson(self::API.'connections/token', ['code' => $code, 'code_verifier' => str_repeat('x', 50), 'instance_id' => 'store-instance-1'])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        // A failed attempt burns the code.
        $this->postJson(self::API.'connections/token', ['code' => $code, 'code_verifier' => self::VERIFIER, 'instance_id' => 'store-instance-1'])->assertStatus(400);

        $code = $this->approve($user);
        $this->postJson(self::API.'connections/token', ['code' => $code, 'code_verifier' => self::VERIFIER, 'instance_id' => 'another-store'])->assertStatus(400);

        $code = $this->approve($user);
        $this->travel(6)->minutes();
        $this->postJson(self::API.'connections/token', ['code' => $code, 'code_verifier' => self::VERIFIER, 'instance_id' => 'store-instance-1'])->assertStatus(400);
        $this->travelBack();

        $code = $this->approve($user);
        $body = $this->postJson(self::API.'connections/token', ['code' => $code, 'code_verifier' => self::VERIFIER, 'instance_id' => 'store-instance-1'])->assertOk()->json();
        $this->assertSame(64, strlen($body['token']));
        $this->assertSame($user->email, $body['account']['email']);
        // Only the hash is stored.
        $this->assertDatabaseMissing('store_connections', ['token_hash' => $body['token']]);
        $this->assertDatabaseHas('store_connections', ['token_hash' => hash('sha256', $body['token'])]);
    }

    public function test_connected_catalog_shows_ownership_and_claim_issues_or_refuses_licenses(): void
    {
        ['token' => $token, 'user' => $user] = $this->connected();
        $free = $this->package('acme/free', true);
        $paid = $this->package('acme/paid', false);
        $owned = $this->package('acme/owned', false);
        app(Licensing::class)->issue($user, $owned, 'paid');

        $products = collect($this->withToken($token)->getJson(self::API.'catalog')->assertOk()->assertJsonPath('connected', true)->json('products'))->keyBy('identifier');
        $this->assertFalse($products['acme/free']['owned']);
        $this->assertTrue($products['acme/owned']['owned']);
        $this->assertArrayNotHasKey('key', $products['acme/owned']['license']);

        // Anonymous catalog has no account fields.
        $this->assertArrayNotHasKey('owned', $this->withToken('')->getJson(self::API.'catalog')->json('products.0'));

        $this->withToken($token)->postJson(self::API.'connections/licenses/claim', ['identifier' => 'acme/paid'])->assertStatus(402)->assertJsonPath('error', 'not_owned');
        $key = $this->withToken($token)->postJson(self::API.'connections/licenses/claim', ['identifier' => 'acme/free'])->assertOk()->json('license.key');
        $this->assertSame(License::query()->where('package_id', $free->id)->value('key'), $key);
        $this->assertSame(['acme/free', 'acme/owned'], collect($this->withToken($token)->getJson(self::API.'connections/licenses')->json('licenses'))->pluck('identifier')->sort()->values()->all());
        $this->assertNull(License::query()->where('package_id', $paid->id)->first());
    }

    public function test_move_here_releases_the_license_from_another_store_only(): void
    {
        ['token' => $token, 'user' => $user] = $this->connected();
        $license = app(Licensing::class)->issue($user, $this->package('acme/owned', false), 'paid');
        LicenseActivation::query()->create(['license_id' => $license->id, 'instance_id' => 'old-store', 'domain' => 'old.example', 'active' => true, 'activated_at' => now()]);

        $this->withToken($token)->getJson(self::API.'connections/licenses')->assertJsonPath('licenses.0.in_use_elsewhere', true);
        $this->withToken($token)->postJson(self::API.'connections/licenses/release', ['identifier' => 'acme/owned'])->assertOk();
        $this->assertDatabaseHas('license_activations', ['instance_id' => 'old-store', 'active' => false]);
    }

    public function test_disconnecting_revokes_the_token_and_other_accounts_stay_isolated(): void
    {
        ['token' => $token] = $this->connected();
        $stranger = User::factory()->create();
        app(Licensing::class)->issue($stranger, $this->package('acme/theirs', true), 'free');

        $this->withToken($token)->getJson(self::API.'connections/licenses')->assertOk()->assertJsonCount(0, 'licenses');
        $this->withToken($token)->getJson(self::API.'connections/me')->assertOk()->assertJsonPath('account.store_name', 'Jhay Store');

        $this->withToken($token)->deleteJson(self::API.'connections/current')->assertOk();
        $this->withToken($token)->getJson(self::API.'connections/me')->assertStatus(401)->assertJsonPath('error', 'not_connected');

        // From the account page too.
        ['token' => $second, 'user' => $owner] = $this->connected();
        $connection = StoreConnection::query()->whereNull('revoked_at')->latest('id')->firstOrFail();
        $this->actingAs($stranger)->post("/account/connections/{$connection->id}/revoke")->assertForbidden();
        $this->actingAs($owner)->post("/account/connections/{$connection->id}/revoke")->assertRedirect('/account');
        $this->withToken($second)->getJson(self::API.'connections/me')->assertStatus(401);
    }
}
