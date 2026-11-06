<?php

namespace Marketplace\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Marketplace\Models\User;

/**
 * Forgot password: emails a one-hour link (sent through the SMTP settings in
 * Admin → Settings), then sets a new password. The request page never says
 * whether an email has an account, so it cannot be used to find customers.
 */
class PasswordResetController
{
    public const SENT = 'If an account uses that email, a link to reset the password is on its way. It works for 60 minutes.';

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function send(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'email', 'max:191']]);

        try {
            // Unknown emails and repeat requests within a minute answer the same way.
            Password::broker()->sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            // A wrong SMTP setup must not reveal which emails exist; it is logged for the admin.
            Log::error('The password reset email could not be sent: '.$e->getMessage());
        }

        return back()->with('notice', self::SENT);
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $status = Password::broker()->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

            // Sign out every other session of this account.
            $table = (string) config('session.table', 'sessions');
            if (config('session.driver') === 'database' && Schema::hasTable($table)) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Ask for a new one.']);
        }

        $admin = (bool) User::query()->where('email', $data['email'])->value('is_admin');

        return redirect($admin ? '/admin/login' : '/account/login')->with('notice', 'Your password was changed. Sign in with the new password.');
    }
}
