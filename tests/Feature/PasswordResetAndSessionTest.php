<?php

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Marketplace\Models\User;
use Marketplace\Providers\AppServiceProvider;
use Marketplace\Services\MailSettings;
use Marketplace\Models\Setting;
use Tests\TestCase;

/** Forgot password / reset, and a session cookie that survives a domain APP_NAME. */
class PasswordResetAndSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_domain_app_name_still_gives_a_cookie_name_php_keeps(): void
    {
        config(['session.cookie' => 'cloud-apps.modrixcommerce.com_session']);
        (new AppServiceProvider($this->app))->register();

        $this->assertSame('cloud-apps_modrixcommerce_com_session', config('session.cookie'));
    }

    public function test_login_pages_link_to_the_reset_page(): void
    {
        $this->get('/account/login')->assertSee('/account/forgot-password', false);
        $this->get('/admin/login')->assertSee('/account/forgot-password', false);
        $this->get('/account/forgot-password')->assertOk()->assertSee('Email me a reset link');
    }

    public function test_the_request_answers_the_same_for_known_and_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->post('/account/forgot-password', ['email' => 'Owner@Example.test'])->assertSessionHas('notice', \Marketplace\Http\Controllers\PasswordResetController::SENT);
        $this->post('/account/forgot-password', ['email' => 'nobody@example.test'])->assertSessionHas('notice', \Marketplace\Http\Controllers\PasswordResetController::SENT);

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_the_emailed_link_sets_a_new_password_once(): void
    {
        Notification::fake();
        $user = User::factory()->admin()->create(['email' => 'owner@example.test', 'password' => 'Old-password-123']);
        $this->post('/account/forgot-password', ['email' => 'owner@example.test']);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token, $user) {
            $token = $n->token;
            $this->assertStringContainsString('/account/reset-password/'.$token, $n->toMail($user)->actionUrl);

            return true;
        });

        $this->get('/account/reset-password/'.$token.'?email=owner@example.test')->assertOk()->assertSee('Choose a new password');

        $this->post('/account/reset-password', ['token' => 'wrong', 'email' => 'owner@example.test', 'password' => 'New-password-1234', 'password_confirmation' => 'New-password-1234'])
            ->assertSessionHasErrors('email');
        $this->post('/account/reset-password', ['token' => $token, 'email' => 'owner@example.test', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->post('/account/reset-password', ['token' => $token, 'email' => 'owner@example.test', 'password' => 'New-password-1234', 'password_confirmation' => 'New-password-1234'])
            ->assertRedirect('/admin/login');

        $this->post('/admin/login', ['email' => 'owner@example.test', 'password' => 'Old-password-123'])->assertSessionHasErrors();
        $this->post('/admin/login', ['email' => 'owner@example.test', 'password' => 'New-password-1234'])->assertSessionHasNoErrors();
        auth()->logout();

        // The link works once.
        $this->post('/account/reset-password', ['token' => $token, 'email' => 'owner@example.test', 'password' => 'Another-pass-1234', 'password_confirmation' => 'Another-pass-1234'])
            ->assertSessionHasErrors('email');
    }

    public function test_reset_emails_go_through_the_admin_smtp_settings(): void
    {
        Setting::put('smtp_host', 'smtp.example.test');
        Setting::put('smtp_port', '2525');
        Setting::put('mail_from', 'no-reply@example.test');

        app(MailSettings::class)->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame('no-reply@example.test', config('mail.from.address'));
    }

    public function test_a_broken_mailer_does_not_reveal_anything(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        User::factory()->create(['email' => 'owner@example.test']);

        $this->post('/account/forgot-password', ['email' => 'owner@example.test'])->assertSessionHas('notice', \Marketplace\Http\Controllers\PasswordResetController::SENT);
    }
}
