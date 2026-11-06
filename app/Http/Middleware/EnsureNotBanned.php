<?php

namespace Marketplace\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A banned account is signed out on its next request, including sessions that were already open. */
class EnsureNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->banned_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect($request->is('admin', 'admin/*') ? '/admin/login' : '/account/login')
                ->withErrors(['email' => 'This account is suspended.']);
        }

        return $next($request);
    }
}
