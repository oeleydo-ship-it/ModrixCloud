@extends('layouts.admin', ['title' => 'Users'])

@section('admin')
    <h1>Users</h1>
    <p class="meta">Registered accounts. A ban signs the user out, blocks sign-in and disconnects their stores; their licenses keep working (revoke those on the Licenses page). Accounts with licenses or orders are kept for your records and cannot be deleted.</p>

    <nav class="tabs" aria-label="Filter">
        @foreach (['all' => 'All', 'admins' => 'Administrators', 'banned' => 'Banned'] as $value => $label)
            <a href="{{ url('/admin/users?'.http_build_query(array_filter(['filter' => $value, 'q' => $q]))) }}" @if ($filter === $value) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>

    <form method="get" class="inline-form">
        <input type="hidden" name="filter" value="{{ $filter }}">
        <label class="sr-only" for="user-q">Search</label>
        <input id="user-q" name="q" value="{{ $q }}" placeholder="Name or email">
        <button type="submit" class="secondary">Search</button>
    </form>

    <div class="table-wrap">
        <table>
            <thead><tr><th>User</th><th>Registered</th><th>Licenses</th><th>Orders</th><th>Stores</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($users as $user)
                @php($self = auth()->id() === $user->id)
                <tr>
                    <td><strong>{{ $user->name }}</strong>@if ($self) <span class="meta">(you)</span>@endif<br><span class="meta">{{ $user->email }}</span></td>
                    <td>{{ $user->created_at?->format('Y-m-d') }}</td>
                    <td>{{ $user->licenses_count }}</td>
                    <td>{{ $user->orders_count }}</td>
                    <td><a href="{{ url('/admin/stores?'.http_build_query(['status' => 'all', 'q' => $user->email])) }}">{{ $user->stores_count }}</a></td>
                    <td>
                        @if ($user->banned_at)
                            <span class="status status-banned">Banned</span><br><span class="meta">{{ $user->banned_at->format('Y-m-d') }}{{ $user->ban_reason ? ' · '.$user->ban_reason : '' }}</span>
                        @elseif ($user->is_admin)
                            <span class="status status-active">Admin</span>
                        @else
                            <span class="status">Customer</span>
                        @endif
                    </td>
                    <td class="actions">
                        @unless ($self)
                            @if ($user->banned_at)
                                <form method="post" action="{{ url('/admin/users/'.$user->id.'/unban') }}">@csrf<button class="secondary small" type="submit">Unban</button></form>
                            @else
                                <form method="post" action="{{ url('/admin/users/'.$user->id.'/admin') }}">@csrf<input type="hidden" name="admin" value="{{ $user->is_admin ? 0 : 1 }}"><button class="secondary small" type="submit">{{ $user->is_admin ? 'Remove admin' : 'Make admin' }}</button></form>
                                <form method="post" action="{{ url('/admin/users/'.$user->id.'/ban') }}" onsubmit="return confirm('Ban this user? They are signed out and their stores are disconnected.')">
                                    @csrf
                                    <label class="sr-only" for="user-ban-{{ $user->id }}">Reason</label>
                                    <input id="user-ban-{{ $user->id }}" name="reason" placeholder="Reason (optional)" maxlength="255" class="small">
                                    <button class="danger small" type="submit">Ban</button>
                                </form>
                            @endif
                            @if ($user->licenses_count === 0 && $user->orders_count === 0)
                                <form method="post" action="{{ url('/admin/users/'.$user->id.'/delete') }}" onsubmit="return confirm('Delete this account? This cannot be undone.')">@csrf<button class="danger small" type="submit">Delete</button></form>
                            @endif
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="meta">No users found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
@endsection
