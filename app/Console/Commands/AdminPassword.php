<?php

namespace Marketplace\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Marketplace\Models\User;

/**
 * Recovery from the server: sets a new password for an account (for example
 * an administrator who cannot sign in) and ends its other sessions.
 */
class AdminPassword extends Command
{
    protected $signature = 'marketplace:admin-password {email : The account email} {--password= : The new password (prompted when left out)}';

    protected $description = 'Set a new password for an account, e.g. a locked-out administrator';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $admins = User::query()->where('is_admin', true)->pluck('email')->implode(', ');
            $this->error("No account uses {$email}.".($admins !== '' ? " Administrators: {$admins}." : ' There is no administrator yet; run marketplace:install.'));

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: $this->secret('New password (at least 12 characters)'));

        if (! $this->option('password') && $password !== (string) $this->secret('Confirm the new password')) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $validator = Validator::make(['password' => $password], ['password' => ['required', Password::min(12)]]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user->forceFill(['password' => $password, 'remember_token' => null])->save();

        if (config('session.driver') === 'database' && Schema::hasTable((string) config('session.table', 'sessions'))) {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $this->info("The password for {$user->email} was changed.".($user->is_admin ? ' Sign in at '.url('/admin/login').'.' : ''));

        if ($user->banned_at !== null) {
            $this->warn('This account is banned, so it still cannot sign in until it is unbanned.');
        }

        return self::SUCCESS;
    }
}
