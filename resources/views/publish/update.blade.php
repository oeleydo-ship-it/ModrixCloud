@extends('layouts.site', ['title' => 'Submit an update'])

@section('content')
    <section class="panel form-panel">
        <span class="eyebrow">PUBLISHERS</span>
        <h1>Submit an update</h1>
        <p class="meta">Upload the new version's ZIP. Its manifest version must match the version below and be higher than the published one. Stores see the update once an admin approves it.</p>
        <form method="post" action="{{ url('/submit/update') }}" enctype="multipart/form-data" class="form-grid">
            @csrf
            @if ($packages->isEmpty())
                <p class="wide meta">There are no published packages to update yet. <a href="{{ url('/submit') }}">Submit a package</a> first.</p>
            @else
                <label>Package
                    <select name="identifier" required>
                        <option value="" disabled @selected(old('identifier') === null)>Choose a package…</option>
                        @foreach ($packages->groupBy('type') as $type => $group)
                            <optgroup label="{{ $type === 'theme' ? 'Themes' : 'Extensions' }}">
                                @foreach ($group as $package)
                                    <option value="{{ $package->identifier }}" @selected(old('identifier') === $package->identifier)>{{ $package->name }} ({{ $package->identifier }}, {{ $package->latestRelease()?->version ?? $package->version }})</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </label>
                <label>New version <input name="version" value="{{ old('version') }}" required placeholder="1.1.0"></label>
                <label>Platform version (optional) <input name="platform_version" value="{{ old('platform_version') }}" placeholder="^1.0"></label>
                <label class="wide">What changed <textarea name="changelog" required maxlength="5000" rows="5">{{ old('changelog') }}</textarea></label>
                <label class="wide">Package ZIP <input type="file" name="package" accept=".zip,application/zip" required></label>
                <div class="wide"><button type="submit">Submit update</button></div>
            @endif
        </form>
    </section>
@endsection
