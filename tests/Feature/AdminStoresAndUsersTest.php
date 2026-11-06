<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Models\BannedStore;
use Marketplace\Models\Order;
use Marketplace\Models\Package;
use Marketplace\Models\StoreConnection;
use Marketplace\Models\User;
use Tests\TestCase;

/** Admin → Connected stores and Admin → Users. */
class AdminStoresAndUsersTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/marketplace/v1/';

    private const VERIFIER = 'a-very-long-random-code-verifier-for-pkce-tests-1234567890';

    private function pairing(array $overrides = []): array
    {
        return [
            'instance_id' => 'store-instance-1',
            'store_name' => 'Jhay Store',
            'domain' => 'localhost:8017',
            'return_url' => 'http://localhost:8017/admin/m/modrixcommerce/cloud-connector/callback',
            'state' => 'state-value-0123456789',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            ...$overrides,
        ];
    }

    /** Pairs a store for $user and returns [token, connection]. */
    private function connect(User $user, array $overrides = []): array
    {
        $location = $this->actingAs($user)->post('/connect', $this->pairing($overrides))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $token = $this->postJson(self::API.'connections/token', ['code' => $query['code'], 'code_verifier' => self::VERIFIER, 'instance_id' => $overrides['instance_id'] ?? 'store-instance-1'])
            ->assertOk()->json('token');

        return [$token, StoreConnection::query()->where('token_hash', hash('sha256', $token))->firstOrFail()];
    }

    private function me(string $token)
    {
        return $this->withToken($token)->getJson(self::API.'connections/me');
    }

    public function test_only_admins_see_the_pages(): void
    {
        $this->get('/admin/stores')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();

        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->connect($owner);
        $this->actingAs(User::factory()->admin()->create())->get('/admin/stores')
            ->assertOk()->assertSee('Jhay Store')->assertSee('owner@example.com');
        $this->get('/admin/users')->assertOk()->assertSee('owner@example.com');
    }

    public function test_disconnect_revokes_the_token_and_the_store_can_connect_again(): void
    {
        $admin = User::factory()->admin()->create();
        [$token, $connection] = $this->connect(User::factory()->create());

        $this->actingAs($admin)->post("/admin/stores/{$connection->id}/disconnect")->assertRedirect();
        $this->me($token)->assertUnauthorized();

        $this->connect(User::factory()->create());
    }

    public function test_banning_a_store_blocks_it_by_installation_and_domain_until_unbanned(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        [$token, $connection] = $this->connect($owner);

        $this->actingAs($admin)->post("/admin/stores/{$connection->id}/ban", ['reason' => 'Abuse'])->assertRedirect();
        $this->me($token)->assertUnauthorized();
        $this->assertDatabaseHas('banned_stores', ['instance_id' => 'store-instance-1', 'domain' => 'localhost', 'reason' => 'Abuse']);

        // Same installation, or a reinstall on the same host, may not connect.
        $this->actingAs($owner)->post('/connect', $this->pairing())->assertSessionHasErrors('store');
        $this->post('/connect', $this->pairing(['instance_id' => 'fresh-install-2', 'domain' => 'localhost:9000', 'return_url' => 'http://localhost:9000/cb']))
            ->assertSessionHasErrors('store');
        $this->get('/connect?'.http_build_query($this->pairing()))->assertOk()->assertSee('not allowed to connect');

        $this->actingAs($admin)->get('/admin/stores?status=banned')->assertOk()->assertSee('Jhay Store');

        $this->post('/admin/stores/bans/'.BannedStore::query()->value('id').'/unban')->assertRedirect();
        $this->connect($owner);
    }

    public function test_only_disconnected_stores_can_be_removed(): void
    {
        $admin = User::factory()->admin()->create();
        [, $connection] = $this->connect(User::factory()->create());

        $this->actingAs($admin)->post("/admin/stores/{$connection->id}/remove")->assertStatus(422);
        $this->post("/admin/stores/{$connection->id}/disconnect");
        $this->post("/admin/stores/{$connection->id}/remove")->assertRedirect();
        $this->assertDatabaseMissing('store_connections', ['id' => $connection->id]);
    }

    public function test_a_banned_user_is_signed_out_cannot_sign_in_and_loses_store_connections(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['email' => 'banned@example.com', 'password' => 'secret-password']);
        [$token] = $this->connect($user);

        $this->actingAs($admin)->post("/admin/users/{$user->id}/ban", ['reason' => 'Fraud'])->assertRedirect();
        $this->assertNotNull($user->fresh()->banned_at);
        $this->me($token)->assertUnauthorized();

        // An open session is ended on its next request.
        $this->actingAs($user->fresh())->get('/account')->assertRedirect('/account/login');
        $this->assertGuest();

        $this->post('/account/login', ['email' => 'banned@example.com', 'password' => 'secret-password'])->assertSessionHasErrors();
        $this->assertGuest();

        // A banned account cannot pair a store.
        $this->actingAs($user->fresh())->post('/connect', $this->pairing());
        $this->assertDatabaseCount('connection_codes', 1);

        $this->actingAs($admin)->post("/admin/users/{$user->id}/unban")->assertRedirect();
        auth()->logout();
        $this->post('/account/login', ['email' => 'banned@example.com', 'password' => 'secret-password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_admin_rights_are_protected(): void
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();

        $this->actingAs($admin)->post("/admin/users/{$admin->id}/admin", ['admin' => 0])->assertSessionHasErrors('user');
        $this->post("/admin/users/{$admin->id}/ban")->assertSessionHasErrors('user');
        $this->post("/admin/users/{$admin->id}/delete")->assertSessionHasErrors('user');
        $this->assertTrue($admin->fresh()->is_admin);

        $this->post("/admin/users/{$customer->id}/admin", ['admin' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($customer->fresh()->is_admin);
        $this->post("/admin/users/{$customer->id}/admin", ['admin' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($customer->fresh()->is_admin);
    }

    public function test_accounts_with_orders_are_kept_and_others_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        $package = Package::query()->create(['identifier' => 'acme/tool', 'type' => 'module', 'slug' => 'acme-tool', 'name' => 'Tool', 'status' => 'published', 'is_free' => false, 'price' => '9.00']);
        Order::query()->create(['user_id' => $buyer->id, 'package_id' => $package->id, 'amount' => '9.00', 'currency' => 'USD', 'status' => 'pending']);
        $empty = User::factory()->create();

        $this->actingAs($admin)->post("/admin/users/{$buyer->id}/delete")->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $buyer->id]);

        $this->post("/admin/users/{$empty->id}/delete")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['id' => $empty->id]);
    }
}
