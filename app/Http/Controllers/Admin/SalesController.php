<?php

namespace Marketplace\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Marketplace\Models\License;
use Marketplace\Models\Order;
use Marketplace\Models\Subscription;

/** Licenses (with their connected stores), orders and subscription sign-ups. */
class SalesController
{
    public function licenses(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('admin.licenses', [
            'q' => $q,
            'licenses' => License::query()
                ->with(['user', 'package', 'activations' => fn ($a) => $a->where('active', true), 'user.subscriptions' => fn ($s) => $s->where('status', '!=', 'pending')->latest('id')])
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                    ->where('key', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$q}%"))
                    ->orWhereHas('package', fn ($p) => $p->where('name', 'like', "%{$q}%")->orWhere('identifier', 'like', "%{$q}%"))
                    ->orWhereHas('activations', fn ($a) => $a->where('domain', 'like', "%{$q}%"))))
                ->latest()->paginate(50)->withQueryString(),
        ]);
    }

    public function release(License $license): RedirectResponse
    {
        $license->activations()->where('active', true)->update(['active' => false, 'deactivated_at' => now()]);

        return back()->with('notice', 'Store disconnected from '.$license->key.'.');
    }

    public function revoke(License $license): RedirectResponse
    {
        $license->forceFill(['status' => 'revoked'])->save();
        $license->activations()->where('active', true)->update(['active' => false, 'deactivated_at' => now()]);

        return back()->with('notice', 'License '.$license->key.' revoked.');
    }

    public function orders(): View
    {
        return view('admin.orders', [
            'orders' => Order::query()->with(['user', 'package', 'license'])->latest()->paginate(50),
            // Subscription sign-ups (the full list is under Subscriptions).
            'subscriptions' => Subscription::query()->with('user')->where('status', '!=', 'pending')->latest('id')->limit(10)->get(),
        ]);
    }
}
