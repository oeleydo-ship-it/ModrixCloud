@extends('layouts.admin', ['title' => 'Connected stores'])

@section('admin')
    <h1>Connected stores</h1>
    <p class="meta">Stores linked to a customer account through the Modrix Cloud Connector. <strong>Disconnect</strong> revokes the store's token (it can connect again); <strong>Ban</strong> also blocks its installation and domain from connecting.</p>

    <nav class="tabs" aria-label="Status">
        @foreach (['active' => 'Connected', 'disconnected' => 'Disconnected', 'banned' => 'Banned', 'all' => 'All'] as $value => $label)
            <a href="{{ url('/admin/stores?'.http_build_query(array_filter(['status' => $value, 'q' => $q]))) }}" @if ($status === $value) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <form method="get" class="inline-form">
        <input type="hidden" name="status" value="{{ $status }}">
        <label class="sr-only" for="store-q">Search</label>
        <input id="store-q" name="q" value="{{ $q }}" placeholder="Store name, domain, installation id or owner email">
        <button type="submit" class="secondary">Search</button>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Store</th><th>Owner</th><th>Connected</th><th>Last seen</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($connections as $connection)
                @php($banned = $isBanned($connection))
                <tr>
                    <td><strong>{{ $connection->store_name }}</strong><br><span class="meta">{{ $connection->domain }}</span><br><code class="meta">{{ $connection->instance_id }}</code></td>
                    <td>{{ $connection->user?->email ?? '—' }}@if ($connection->user?->banned_at) <span class="status status-banned">Banned</span>@endif</td>
                    <td>{{ $connection->created_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $connection->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                    <td>
                        @if ($banned)
                            <span class="status status-banned">Banned</span>
                        @elseif ($connection->revoked_at)
                            <span class="status status-disconnected">Disconnected</span>
                        @else
                            <span class="status status-active">Connected</span>
                        @endif
                    </td>
                    <td class="actions">
                        @if (! $connection->revoked_at)
                            <form method="post" action="{{ url('/admin/stores/'.$connection->id.'/disconnect') }}" onsubmit="return confirm('Disconnect this store? It can connect again later.')">@csrf<button class="secondary small" type="submit">Disconnect</button></form>
                        @endif
                        @unless ($banned)
                            <form method="post" action="{{ url('/admin/stores/'.$connection->id.'/ban') }}" onsubmit="return confirm('Ban this store? Every connection of this installation and domain is revoked and it cannot connect again.')">
                                @csrf
                                <label class="sr-only" for="ban-reason-{{ $connection->id }}">Reason</label>
                                <input id="ban-reason-{{ $connection->id }}" name="reason" placeholder="Reason (optional)" maxlength="255" class="small">
                                <button class="danger small" type="submit">Ban</button>
                            </form>
                        @endunless
                        @if ($connection->revoked_at)
                            <form method="post" action="{{ url('/admin/stores/'.$connection->id.'/remove') }}" onsubmit="return confirm('Remove this record? This cannot be undone.')">@csrf<button class="secondary small" type="submit">Remove</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="meta">No stores found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $connections->links() }}

    <h2>Banned stores</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Domain</th><th>Installation id</th><th>Reason</th><th>Banned</th><th></th></tr></thead>
            <tbody>
            @forelse ($bans as $ban)
                <tr>
                    <td>{{ $ban->domain ?: '—' }}</td>
                    <td><code class="meta">{{ $ban->instance_id ?: '—' }}</code></td>
                    <td>{{ $ban->reason ?: '—' }}</td>
                    <td>{{ $ban->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="actions">
                        <form method="post" action="{{ url('/admin/stores/bans/'.$ban->id.'/unban') }}" onsubmit="return confirm('Lift this ban? The store will be able to connect again.')">@csrf<button class="secondary small" type="submit">Unban</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="meta">No stores are banned.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
