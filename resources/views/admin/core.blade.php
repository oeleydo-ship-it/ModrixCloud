@extends('layouts.admin', ['title' => 'Core releases'])

@section('admin')
    <h1>Core releases</h1>
    <p class="meta">Platform packages stores can install as one-click core updates. Upload a package, then publish it to make it available.</p>

    <h2>Upload a release</h2>
    <form method="post" action="{{ url('/admin/core') }}" enctype="multipart/form-data" class="form-grid">
        @csrf
        <label>Version <input name="version" value="{{ old('version') }}" required maxlength="32" placeholder="1.1.0"></label>
        <label>Minimum PHP <input name="min_php" value="{{ old('min_php', '8.3') }}" required maxlength="16" placeholder="8.3"></label>
        <label class="wide">Release notes <textarea name="notes" rows="5" maxlength="20000">{{ old('notes') }}</textarea></label>
        <label class="wide">Package (ZIP) <input type="file" name="package" accept=".zip,application/zip" required></label>
        <div class="wide"><button type="submit">Upload release</button></div>
    </form>

    <h2>Releases</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Version</th><th>Status</th><th>Min PHP</th><th>Size</th><th>SHA-256</th><th>Published</th><th></th></tr></thead>
            <tbody>
            @forelse ($releases as $release)
                <tr>
                    <td>{{ $release->version }}</td>
                    <td><span class="status status-{{ $release->status }}">{{ ucfirst($release->status) }}</span></td>
                    <td>{{ $release->min_php }}</td>
                    <td>{{ number_format($release->size / 1048576, 1) }} MB</td>
                    <td><code>{{ substr($release->sha256, 0, 12) }}</code></td>
                    <td>{{ $release->published_at?->format('Y-m-d H:i') }}</td>
                    <td class="row-actions">
                        @if ($release->status === 'draft')
                            <form method="post" action="{{ url('/admin/core/'.$release->id.'/publish') }}">@csrf<button type="submit">Publish</button></form>
                        @endif
                        @if ($release->status !== 'withdrawn')
                            <form method="post" action="{{ url('/admin/core/'.$release->id.'/withdraw') }}" onsubmit="return confirm('Withdraw this release?')">@csrf<button type="submit" class="secondary">Withdraw</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="meta">No core releases yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
