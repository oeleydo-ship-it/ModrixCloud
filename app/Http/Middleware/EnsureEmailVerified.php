<?php

namespace Marketplace\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Marketplace\Services\EmailVerification;
use Symfony\Component\HttpFoundation\Response;

/** While email verification is required, unverified customers see the "check your email" page instead of account pages. */
class EnsureEmailVerified
{
    public function __construct(private readonly EmailVerification $verification) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->verification->blocks($request->user())) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Confirm your email address first.'], 403)
                : redirect('/account/verify-email');
        }

        return $next($request);
    }
}
