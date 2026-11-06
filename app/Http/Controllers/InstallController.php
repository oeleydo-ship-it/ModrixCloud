<?php

namespace Marketplace\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Marketplace\Models\User;
use Marketplace\Services\Installer;
use Marketplace\Services\InstallerException;

/**
 * /install: step 1 MySQL/MariaDB, step 2 the first administrator. When .env
 * already has a migrated database (a deploy ran `php artisan migrate`), step 1
 * is skipped; the admin step then asks for that database's password, so only
 * whoever controls the server can claim the marketplace.
 */
class InstallController
{
    public function __construct(private readonly Installer $installer) {}

    public function show(Request $request): View
    {
        $mode = $this->mode($request);

        return view('install.show', [
            'step' => $mode === null ? 'database' : 'admin',
            'preconfigured' => $mode === 'preconfigured',
            'confirmDatabasePassword' => $this->needsDatabasePassword($mode),
            'hasAdmin' => $mode !== null && User::query()->where('is_admin', true)->exists(),
            'requirements' => [
                'PHP 8.3 or newer' => version_compare(PHP_VERSION, '8.3.0', '>='),
                'pdo_mysql extension' => extension_loaded('pdo_mysql'),
                'zip extension' => extension_loaded('zip'),
                '.env is writable' => is_writable(app()->environmentFilePath()),
                'storage/ is writable' => is_writable(storage_path()),
            ],
        ]);
    }

    public function database(Request $request): RedirectResponse
    {
        $db = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'username' => ['required', 'string', 'max:80'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->installer->setUpDatabase($db);
        } catch (InstallerException $e) {
            return back()->withInput($request->except('password'))->withErrors(['host' => $e->getMessage()]);
        }

        $request->session()->put('install.database', true);

        return redirect('/install');
    }

    public function admin(Request $request): View|RedirectResponse
    {
        $mode = $this->mode($request);

        if ($mode === null) {
            return redirect('/install');
        }

        if ($this->needsDatabasePassword($mode)
            && ! hash_equals($this->installer->configuredDatabasePassword(), (string) $request->input('database_password'))) {
            throw ValidationException::withMessages(['database_password' => 'That is not the database password in this server’s .env.']);
        }

        if (! User::query()->where('is_admin', true)->exists()) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:191', 'unique:users,email'],
                'password' => ['required', 'confirmed:password_confirmation', Password::min(12)],
            ]);

            $admin = User::query()->create($data);
            $admin->forceFill(['is_admin' => true])->save();
        }

        if ($mode === 'preconfigured') {
            try {
                $this->installer->seedDefaults();
            } catch (InstallerException $e) {
                return back()->withErrors(['email' => $e->getMessage()]);
            }
        }

        $this->installer->markInstalled();
        $request->session()->forget('install.database');

        return view('install.done');
    }

    /** 'wizard' after step 1 in this session, 'preconfigured' when .env already has a migrated database, else null. */
    private function mode(Request $request): ?string
    {
        if ($request->session()->get('install.database')) {
            return 'wizard';
        }

        return $this->installer->preconfiguredDatabase() ? 'preconfigured' : null;
    }

    private function needsDatabasePassword(?string $mode): bool
    {
        return $mode === 'preconfigured' && $this->installer->configuredDatabasePassword() !== '';
    }
}
