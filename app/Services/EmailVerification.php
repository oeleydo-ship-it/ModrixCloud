<?php

namespace Marketplace\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Marketplace\Models\Setting;
use Marketplace\Models\User;
use Throwable;

/**
 * "Require email verification for new accounts" (Admin → Settings → Email).
 *
 * Accounts registered while the option is off are verified on creation, and
 * the migration verified every account that existed before it, so turning the
 * option on only affects accounts registered after that. Admins are never held.
 */
class EmailVerification
{
    public const SETTING = 'require_email_verification';

    public function required(): bool
    {
        try {
            return (bool) Setting::get(self::SETTING, false);
        } catch (QueryException) {
            return false; // Not installed yet.
        }
    }

    /** Whether this account must confirm its email before using account pages. */
    public function blocks(?User $user): bool
    {
        return $user !== null && ! $user->is_admin && ! $user->hasVerifiedEmail() && $this->required();
    }

    /** Sends the signed link; a mail failure is logged for the admin and reported as false. */
    public function send(User $user): bool
    {
        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (Throwable $e) {
            Log::error('The email verification message could not be sent: '.$e->getMessage());

            return false;
        }
    }
}
