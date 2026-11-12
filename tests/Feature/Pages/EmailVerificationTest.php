<?php

namespace Tests\Feature\Pages;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Marketplace\Models\Setting;
use Marketplace\Models\User;
use Marketplace\Services\EmailVerification;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** "Require email verification for new accounts" (Admin → Settings → Email). */
class EmailVerificationTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    private const FORM = ['name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'Secret-pass-1', 'password_confirmation' => 'Secret-pass-1', 'next' => '/account'];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function requireVerification(bool $on = true): void
    {
        Setting::put(EmailVerification::SETTING, $on);
    }

    private function link(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($user->email)]);
    }

    private function registered(): User
    {
        $this->post('/account/register', self::FORM);

        return User::query()->where('email', 'ana@example.test')->firstOrFail();
    }

    public function test_with_verification_off_registration_sends_nothing_and_gives_full_access()
    {
        $this->post('/account/register', self::FORM)->assertRedirect('/account');

        $user = User::query()->where('email', 'ana@example.test')->firstOrFail();
        Notification::assertNothingSent();
        $this->assertTrue($user->hasVerifiedEmail());

        $package = $this->package();
        $this->get('/account')->assertOk();
        $this->get('/submit')->assertOk();
        $this->post('/account/licenses', ['product' => $package->slug])->assertRedirect('/account');
        $this->get('/account/verify-email')->assertRedirect('/account');
    }

    public function test_with_verification_on_registration_emails_a_link_and_shows_the_check_your_email_page()
    {
        $this->requireVerification();

        $this->post('/account/register', self::FORM)->assertRedirect('/account/verify-email');
        $this->assertAuthenticated();

        $user = User::query()->where('email', 'ana@example.test')->firstOrFail();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get('/account/verify-email')->assertOk()->assertSee('Check your email')->assertSee('ana@example.test')->assertSee('Resend the link');
    }

    public function test_unverified_customers_are_kept_from_account_pages()
    {
        $this->requireVerification();
        $user = $this->registered();
        $package = $this->package(['is_free' => false, 'price' => '49.00']);
        $free = $this->package();

        $this->get('/account')->assertRedirect('/account/verify-email');
        $this->get('/submit')->assertRedirect('/account/verify-email');
        $this->get('/submissions')->assertRedirect('/account/verify-email');
        $this->get('/connect')->assertRedirect('/account/verify-email');
        $this->post('/buy/'.$package->slug)->assertRedirect('/account/verify-email');
        $this->post('/account/licenses', ['product' => $free->slug])->assertRedirect('/account/verify-email');
        $this->assertSame(0, $user->licenses()->count());

        // Signing in again still works, and lands on the same page.
        $this->post('/account/logout');
        $this->post('/account/login', ['email' => 'ana@example.test', 'password' => 'Secret-pass-1'])->assertRedirect('/account');
        $this->get('/account')->assertRedirect('/account/verify-email');
    }

    public function test_the_emailed_link_verifies_the_account()
    {
        $this->requireVerification();
        $user = $this->registered();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url) {
            $mail = $notification->toMail($user);
            $url = $mail->actionUrl;

            return str_contains((string) $mail->render(), 'Confirm email');
        });

        $this->get($url)->assertRedirect('/account')->assertSessionHas('notice');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get('/account')->assertOk();
        $this->get('/submit')->assertOk();
    }

    public function test_the_link_works_when_signed_out()
    {
        $this->requireVerification();
        $user = $this->registered();
        $this->post('/account/logout');

        $this->get($this->link($user))->assertRedirect('/account/login?next=%2Faccount');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_invalid_or_expired_links_are_refused()
    {
        $this->requireVerification();
        $user = $this->registered();

        // Tampered signature, wrong email hash, another account's id, expired.
        $this->get($this->link($user).'x')->assertRedirect('/account/verify-email')->assertSessionHasErrors('email');
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('other@example.test')]))->assertRedirect('/account/verify-email');
        $other = User::factory()->unverified()->create();
        $this->get(str_replace('/'.$user->id.'/', '/'.$other->id.'/', $this->link($user)))->assertRedirect('/account/verify-email');

        $expired = $this->link($user, 60);
        $this->travel(61)->minutes();
        $this->get($expired)->assertRedirect('/account/verify-email');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());

        // Signed out, a bad link is a plain 403.
        $this->post('/account/logout');
        $this->get($expired)->assertForbidden();
    }

    public function test_resend_sends_a_new_link_and_is_throttled()
    {
        $this->requireVerification();
        $user = $this->registered();
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);

        // Registration just sent one, so wait before the first resend.
        $this->travel(61)->seconds();
        $this->post('/account/verify-email/resend')->assertRedirect('/account/verify-email')->assertSessionHas('notice');
        Notification::assertSentToTimes($user, VerifyEmail::class, 2);

        $this->post('/account/verify-email/resend')->assertRedirect('/account/verify-email')->assertSessionHasErrors('email');
        Notification::assertSentToTimes($user, VerifyEmail::class, 2);

        $this->travel(61)->seconds();
        $this->post('/account/verify-email/resend')->assertSessionHas('notice');
        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
    }

    public function test_resend_needs_sign_in_and_does_nothing_for_verified_accounts()
    {
        $this->post('/account/verify-email/resend')->assertRedirect();
        $this->assertGuest();

        $this->requireVerification();
        $this->actingAs($this->customer())->post('/account/verify-email/resend')->assertRedirect('/account');
        Notification::assertNothingSent();
    }

    public function test_turning_verification_on_does_not_lock_out_existing_accounts()
    {
        // Registered while the option was off.
        $this->post('/account/register', self::FORM);
        $this->post('/account/logout');
        $existing = $this->customer();

        $this->actingAs($this->admin())->post('/admin/verification', ['require_email_verification' => '1'])->assertRedirect('/admin/general?tab=email');
        $this->assertTrue(app(EmailVerification::class)->required());
        $this->post('/admin/logout');

        $this->actingAs($existing)->get('/account')->assertOk();
        $this->post('/account/logout');
        $this->post('/account/login', ['email' => 'ana@example.test', 'password' => 'Secret-pass-1']);
        $this->get('/account')->assertOk();
        $this->get('/submit')->assertOk();
        Notification::assertNothingSent();
    }

    public function test_the_migration_marks_existing_accounts_verified()
    {
        $user = User::factory()->unverified()->create();
        $migration = require database_path('migrations/2026_11_12_000001_add_email_verification.php');

        $migration->up();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_admins_are_never_held()
    {
        $this->requireVerification();
        $admin = User::factory()->unverified()->admin()->create();

        $this->actingAs($admin)->get('/account')->assertOk();
        $this->get('/submit')->assertOk();
        $this->get('/admin')->assertOk();
        $this->get('/account/verify-email')->assertRedirect('/account');
    }

    public function test_turning_it_off_lets_unverified_customers_in()
    {
        $this->requireVerification();
        $this->registered();
        $this->get('/account')->assertRedirect('/account/verify-email');

        $this->requireVerification(false);
        $this->get('/account')->assertOk();
    }

    public function test_admin_setting_toggles_and_warns_without_smtp()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/general?tab=email')->assertOk()
            ->assertSee('Require email verification for new accounts')
            ->assertSee('verification emails can&#039;t be sent', false);

        $this->post('/admin/verification', ['require_email_verification' => '1'])
            ->assertRedirect('/admin/general?tab=email')
            ->assertSessionHas('warning');
        $this->assertTrue((bool) Setting::get(EmailVerification::SETTING));

        $this->post('/admin/verification', ['require_email_verification' => '0'])->assertSessionMissing('warning');
        $this->assertFalse((bool) Setting::get(EmailVerification::SETTING));
    }

    public function test_no_warning_once_smtp_is_saved()
    {
        Setting::put('smtp_host', 'smtp.example.test');

        $this->actingAs($this->admin())->get('/admin/general?tab=email')->assertOk()->assertDontSee('verification emails can&#039;t be sent', false);
        $this->post('/admin/verification', ['require_email_verification' => '1'])->assertSessionMissing('warning');
    }

    public function test_customers_cannot_change_the_setting()
    {
        $this->actingAs($this->customer())->post('/admin/verification', ['require_email_verification' => '1'])->assertForbidden();
        $this->assertFalse(app(EmailVerification::class)->required());
    }
}
