<?php

namespace Marketplace\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Marketplace\Models\User;
use Marketplace\Services\EmailVerification;

/**
 * The "check your email" page, the resend button and the signed link from the
 * verification email (valid for 60 minutes, see auth.verification.expire).
 */
class EmailVerificationController
{
    /** One resend per account per minute. */
    public const RESEND_DECAY = 60;

    public function __construct(private readonly EmailVerification $verification) {}

    public function notice(Request $request): View|RedirectResponse
    {
        if (! $this->verification->blocks($request->user())) {
            return redirect('/account');
        }

        return view('auth.verify-email', ['email' => $request->user()->email]);
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $this->verification->blocks($user)) {
            return redirect('/account');
        }

        $key = 'verify-email:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return redirect('/account/verify-email')->withErrors([
                'email' => 'A link was sent a moment ago. You can ask for another in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        RateLimiter::hit($key, self::RESEND_DECAY);

        if (! $this->verification->send($user)) {
            return redirect('/account/verify-email')->withErrors(['email' => 'The email could not be sent right now. Please try again later.']);
        }

        return redirect('/account/verify-email')->with('notice', 'A new link is on its way to '.$user->email.'.');
    }

    /** The link proves the address: it works whether or not this browser is signed in. */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = (string) $request->user()?->getKey() === $id ? $request->user() : User::query()->find($id);

        if (! $request->hasValidSignature() || $user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            if ($request->user() !== null && $this->verification->blocks($request->user())) {
                return redirect('/account/verify-email')->withErrors(['email' => 'That link is invalid or has expired. Send a new one below.']);
            }

            abort(403, 'This verification link is invalid or has expired.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        if ($request->user() === null) {
            return redirect('/account/login?next=%2Faccount')->with('notice', 'Your email is confirmed. Sign in to continue.');
        }

        return redirect('/account')->with('notice', 'Your email is confirmed. Your account is ready.');
    }
}
