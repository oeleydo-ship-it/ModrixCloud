<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Models\User;
use Marketplace\Services\Installer;
use Tests\TestCase;

/** MARKETPLACE_REOPEN_INSTALLER: recover admin access on an installed marketplace. */
class ReopenInstallerTest extends TestCase
{
    use RefreshDatabase;

    private function reopen(string $databasePassword = 'db-secret'): void
    {
        config(['marketplace.reopen_installer' => true]);
        $this->app->instance(Installer::class, new class($databasePassword) extends Installer
        {
            public function __construct(private string $databasePassword) {}

            public function configuredDatabasePassword(): string
            {
                return $this->databasePassword;
            }
        });
    }

    private function form(array $overrides = []): array
    {
        return ['email' => 'Owner@Example.test', 'password' => 'Fresh-password-123', 'password_confirmation' => 'Fresh-password-123', 'database_password' => 'db-secret', ...$overrides];
    }

    public function test_the_installer_stays_closed_without_the_flag(): void
    {
        $this->get('/install')->assertNotFound();
        $this->post('/install/admin', $this->form())->assertNotFound();
    }

    public function test_reopened_it_shows_only_the_admin_reset(): void
    {
        $this->reopen();

        $this->get('/install')->assertOk()->assertSee('Reset admin access')->assertDontSee('Connect MySQL')->assertSee('database_password', false);
        // The database step stays closed.
        $this->post('/install/database', ['host' => 'evil', 'port' => 3306, 'database' => 'x', 'username' => 'x'])->assertNotFound();
        // The rest of the site keeps working.
        $this->get('/')->assertOk();
    }

    public function test_it_needs_the_database_password(): void
    {
        $user = User::factory()->admin()->create(['email' => 'owner@example.test', 'password' => 'Old-password-123']);
        $this->reopen();

        $this->post('/install/admin', $this->form(['database_password' => 'guess']))->assertSessionHasErrors('database_password');

        $this->post('/admin/login', ['email' => 'owner@example.test', 'password' => 'Old-password-123'])->assertSessionHasNoErrors();
        $this->assertTrue(password_verify('Old-password-123', $user->fresh()->password));
    }

    public function test_it_sets_a_new_password_for_an_existing_account_and_makes_it_an_unbanned_admin(): void
    {
        User::factory()->create(['email' => 'owner@example.test', 'password' => 'Old-password-123', 'banned_at' => now()]);
        $this->reopen();

        $this->post('/install/admin', $this->form())->assertOk()->assertSee('Admin access saved')->assertSee('MARKETPLACE_REOPEN_INSTALLER');

        $user = User::query()->where('email', 'owner@example.test')->first();
        $this->assertTrue($user->is_admin);
        $this->assertNull($user->banned_at);
        $this->assertSame(1, User::query()->count());

        $this->post('/admin/login', ['email' => 'owner@example.test', 'password' => 'Fresh-password-123'])->assertSessionHasNoErrors();
        $this->get('/admin')->assertOk();
    }

    public function test_an_unknown_email_becomes_a_new_admin(): void
    {
        $this->reopen();

        $this->post('/install/admin', $this->form(['email' => 'new-admin@example.test', 'name' => 'New Admin']))->assertOk();

        $this->assertTrue(User::query()->where('email', 'new-admin@example.test')->value('is_admin'));
        $this->post('/admin/login', ['email' => 'new-admin@example.test', 'password' => 'Fresh-password-123'])->assertSessionHasNoErrors();
    }
}
