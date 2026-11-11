@extends('layouts.admin', ['title' => 'Repository'])

@section('admin')
    <div class="admin-heading">
        <h1>Repository</h1>
        <a class="button" href="{{ url('/admin/repository/new') }}">Add a package</a>
    </div>
    @if ($bundled['total'] > 0)
        <form method="post" action="{{ url('/admin/repository/bundled') }}" class="inline-form">
            @csrf
            <p class="meta">
                {{ $bundled['total'] }} modules and themes ship with this marketplace.
                @if ($bundled['missing'] === [])
                    All of them are published.
                @else
                    {{ count($bundled['missing']) }} are not published yet: {{ implode(', ', array_slice($bundled['missing'], 0, 8)) }}{{ count($bundled['missing']) > 8 ? ', …' : '' }}.
                @endif
            </p>
            @if ($bundled['missing'] !== [])
                <button type="submit">Publish bundled packages</button>
            @endif
        </form>
    @endif
    <form method="get" class="inline-form">
        <label class="sr-only" for="repo-q">Search</label>
        <input id="repo-q" name="q" value="{{ $q }}" placeholder="Search name or identifier">
        <button type="submit" class="secondary">Search</button>
    </form>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Package</th><th>Type</th><th>Status</th><th>Price</th><th>Version</th></tr></thead>
            <tbody>
            @forelse ($packages as $package)
                <tr>
                    <td><a href="{{ url('/admin/repository/'.$package->slug) }}">{{ $package->name }}</a><br><span class="meta">{{ $package->identifier }}</span></td>
                    <td>{{ $package->type === 'theme' ? 'Theme' : 'Extension' }}</td>
                    <td><span class="status status-{{ $package->status }}">{{ ucfirst($package->status) }}</span></td>
                    <td>{{ $package->is_free ? 'Free' : app(\Marketplace\Services\Catalog::class)->currency().' '.$package->price }}</td>
                    <td>{{ $package->version }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="meta">No packages found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $packages->links() }}
@endsection
