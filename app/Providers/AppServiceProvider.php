<?php

namespace Marketplace\Providers;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Pagination\Paginator;
use Marketplace\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Marketplace\Services\MailSettings;
use Marketplace\Services\SigningKey;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Read from the database once per request.
        $this->app->scoped(SigningKey::class);

        // The session cookie is named after APP_NAME. A name with dots or
        // spaces (a host sets APP_NAME to the domain) is renamed by PHP on
        // the way in ("a.b_session" arrives as "a_b_session"), so the session
        // is lost on every request: CSRF fails and nobody can stay signed in.
        $cookie = (string) config('session.cookie');
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $cookie);
        if ($safe !== $cookie) {
            config(['session.cookie' => $safe]);
        }
    }

    public function boot(): void
    {
        // Indexed strings fit MySQL 5.7 / MariaDB 10.3 utf8mb4 index limits.
        Schema::defaultStringLength(191);

        // The site has no Tailwind, so Laravel's default page links render giant arrows.
        Paginator::defaultView('partials.pagination');

        // SMTP settings saved in the admin, applied before the first mailer is built.
        $this->app->afterResolving('mail.manager', fn () => $this->app->make(MailSettings::class)->apply());

        // The email sent when "Require email verification for new accounts" is on.
        VerifyEmail::toMailUsing(function (object $user, string $url) {
            $name = (string) (Setting::get('catalog_name') ?: config('app.name'));

            return (new MailMessage)
                ->subject('Confirm your email for '.$name)
                ->greeting('Hello '.$user->name.',')
                ->line('Open the link below to confirm your email address and start using your '.$name.' account.')
                ->action('Confirm email', $url)
                ->line('The link works for 60 minutes. If you did not create an account, you can ignore this email.');
        });
    }
}
