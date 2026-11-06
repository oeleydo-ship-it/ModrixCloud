<?php

namespace Marketplace\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Marketplace\Models\BannedStore;
use Marketplace\Models\StoreConnection;
use Marketplace\Services\Connections;

/**
 * Admin → Connected stores: stores linked through the Modrix Cloud
 * Connector. Disconnect revokes a store's token (it may connect again), ban
 * also blocks the store by installation and domain, remove deletes a
 * disconnected record.
 */
class StoreAdminController
{
    public function __construct(private readonly Connections $connections) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'active');
        $bannedInstances = BannedStore::query()->whereNotNull('instance_id')->pluck('instance_id')->all();
        $bannedDomains = BannedStore::query()->whereNotNull('domain')->pluck('domain')->all();

        $connections = StoreConnection::query()
            ->with('user')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('store_name', 'like', "%{$q}%")
                ->orWhere('domain', 'like', "%{$q}%")
                ->orWhere('instance_id', 'like', "%{$q}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$q}%"))))
            ->when($status === 'active', fn ($query) => $query->whereNull('revoked_at'))
            ->when($status === 'disconnected', fn ($query) => $query->whereNotNull('revoked_at'))
            ->when($status === 'banned', fn ($query) => $query->where(function ($w) use ($bannedInstances, $bannedDomains) {
                $w->whereIn('instance_id', $bannedInstances ?: ['']);
                // Bans hold the host; connections may include a port.
                foreach ($bannedDomains as $host) {
                    $w->orWhereRaw('lower(domain) = ?', [$host])->orWhereRaw('lower(domain) like ?', [$host.':%']);
                }
            }))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.stores', [
            'q' => $q,
            'status' => $status,
            'connections' => $connections,
            'isBanned' => fn (StoreConnection $c) => BannedStore::blocks($c->instance_id, $c->domain),
            'bans' => BannedStore::query()->latest()->limit(100)->get(),
        ]);
    }

    public function disconnect(StoreConnection $connection): RedirectResponse
    {
        $this->connections->revoke($connection);

        return back()->with('notice', "{$connection->store_name} was disconnected. It can connect again.");
    }

    public function ban(Request $request, StoreConnection $connection): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        DB::transaction(function () use ($request, $connection, $data) {
            BannedStore::query()->forceCreate([
                'instance_id' => $connection->instance_id,
                'domain' => BannedStore::host($connection->domain),
                'reason' => $data['reason'] ?? null,
                'banned_by' => $request->user()->id,
            ]);
            // Every connection of this installation or domain, not just this one.
            StoreConnection::query()->whereNull('revoked_at')
                ->where(fn ($w) => $w->where('instance_id', $connection->instance_id)->orWhere('domain', $connection->domain))
                ->update(['revoked_at' => now()]);
        });

        return back()->with('notice', "{$connection->store_name} ({$connection->domain}) was banned and disconnected.");
    }

    public function unban(BannedStore $ban): RedirectResponse
    {
        $ban->delete();

        return back()->with('notice', 'The store ban was lifted. The store can connect again.');
    }

    public function destroy(StoreConnection $connection): RedirectResponse
    {
        abort_if($connection->revoked_at === null, 422, 'Disconnect the store before removing it.');
        $connection->delete();

        return back()->with('notice', "The record of {$connection->store_name} was removed.");
    }
}
