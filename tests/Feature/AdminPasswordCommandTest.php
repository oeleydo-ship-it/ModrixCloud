<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marketplace\Models\User;
use Tests\TestCase;

/** php artisan marketplace:admin-password: recovery for a locked-out account. */
class AdminPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_a_new_password_that_signs_in(): void
    {
        User::factory()->admin()->create(['email' => 'owner@example.test', 'password' => 'Forgotten-password-1']);

        $this->artisan('marketplace:admin-password', ['email' => 'Owner@Example.test'])
            ->expectsQuestion('New password (at least 12 characters)', 'Brand-new-password-2')
            ->expectsQuestion('Confirm the new password', 'Brand-new-password-2')
            ->assertSuccessful();

        $this->post('/admin/login', ['email' => 'owner@example.test', 'password' => 'Brand-new-password-2'])->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_it_refuses_unknown_accounts_mismatches_and_short_passwords(): void
    {
        User::factory()->admin()->create(['email' => 'owner@example.test']);

        $this->artisan('marketplace:admin-password', ['email' => 'nobody@example.test'])
            ->expectsOutputToContain('Administrators: owner@example.test')
            ->assertFailed();

        $this->artisan('marketplace:admin-password', ['email' => 'owner@example.test'])
            ->expectsQuestion('New password (at least 12 characters)', 'Brand-new-password-2')
            ->expectsQuestion('Confirm the new password', 'Different-password-3')
            ->assertFailed();

        $this->artisan('marketplace:admin-password', ['email' => 'owner@example.test', '--password' => 'short'])->assertFailed();
    }
}
