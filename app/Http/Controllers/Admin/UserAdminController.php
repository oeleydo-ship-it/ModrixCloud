<?php

namespace Marketplace\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Marketplace\Models\StoreConnection;
use Marketplace\Models\User;

/**
 * Admin → Users: registered accounts. Ban blocks sign-in and store
 * connections (licenses keep working; revoke them on the Licenses page),
 * admin rights can be granted and removed (never your own or the last
 * admin's), and accounts without licenses or orders can be deleted.
 */
class UserAdminController
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'all');

        $users = User::query()
            ->withCount(['licenses', 'orders'])
            ->withCount(['connections as stores_count' => fn ($c) => $c->whereNull('revoked_at')])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('email', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->when($filter === 'admins', fn ($query) => $query->where('is_admin', true))
            ->when($filter === 'banned', fn ($query) => $query->whereNotNull('banned_at'))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.users', ['q' => $q, 'filter' => $filter, 'users' => $users]);
    }

    public function ban(Request $request, User $user): RedirectResponse
    {
        $this->notSelf($request, $user, 'You cannot ban your own account.');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        if ($user->is_admin && $this->lastAdmin($user)) {
            throw ValidationException::withMessages(['user' => 'This is the only administrator; make someone else an admin first.']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['banned_at' => now(), 'ban_reason' => $data['reason'] ?? null])->save();
            StoreConnection::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });

        return back()->with('notice', "{$user->email} was banned: signed out and their stores disconnected. Their licenses keep working.");
    }

    public function unban(User $user): RedirectResponse
    {
        $user->forceFill(['banned_at' => null, 'ban_reason' => null])->save();

        return back()->with('notice', "{$user->email} can sign in again.");
    }

    public function admin(Request $request, User $user): RedirectResponse
    {
        $make = $request->boolean('admin');

        if (! $make) {
            $this->notSelf($request, $user, 'You cannot remove your own admin rights.');

            if ($this->lastAdmin($user)) {
                throw ValidationException::withMessages(['user' => 'This is the only administrator.']);
            }
        }

        abort_if($make && $user->banned_at !== null, 422, 'Unban this account first.');
        $user->forceFill(['is_admin' => $make])->save();

        return back()->with('notice', $make ? "{$user->email} is now an administrator." : "{$user->email} is no longer an administrator.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->notSelf($request, $user, 'You cannot delete your own account.');

        if ($user->licenses()->exists() || $user->orders()->exists()) {
            throw ValidationException::withMessages(['user' => "{$user->email} has licenses or orders, so it is kept for your records. Ban it instead."]);
        }

        if ($user->is_admin && $this->lastAdmin($user)) {
            throw ValidationException::withMessages(['user' => 'This is the only administrator.']);
        }

        $user->delete();

        return back()->with('notice', "{$user->email} was deleted.");
    }

    private function notSelf(Request $request, User $user, string $message): void
    {
        if ($request->user()->id === $user->id) {
            throw ValidationException::withMessages(['user' => $message]);
        }
    }

    private function lastAdmin(User $user): bool
    {
        return $user->is_admin && User::query()->where('is_admin', true)->whereNull('banned_at')->whereKeyNot($user->id)->doesntExist();
    }
}
