@extends('layouts.site', ['title' => 'Your submissions'])

@section('content')
    <section class="panel">
        <span class="eyebrow">PUBLISHERS</span>
        <h1>Your submissions</h1>
        <p><a class="button secondary" href="{{ url('/submit') }}">Submit a package</a> <a class="button secondary" href="{{ url('/submit/update') }}">Submit an update</a></p>
        @if ($submissions->isEmpty())
            <p class="meta">Nothing submitted yet.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Package</th><th>Kind</th><th>Version</th><th>Status</th><th>Compatibility</th><th>Reviewer note</th></tr></thead>
                    <tbody>
                    @foreach ($submissions as $submission)
                        <tr>
                            <td>{{ $submission->created_at->format('Y-m-d') }}</td>
                            <td>{{ $submission->data['name'] ?? $submission->identifier }}<br><span class="meta">{{ $submission->identifier }}</span></td>
                            <td>{{ $submission->kind === 'update' ? 'Update' : 'New package' }}</td>
                            <td>{{ $submission->data['version'] ?? '' }}</td>
                            <td><span class="status status-{{ $submission->status }}">{{ ucfirst($submission->status) }}</span></td>
                            @php($scan = \Marketplace\Services\CompatibilityReport::fromArray($submission->data['compatibility'] ?? null))
                            <td>
                                @if ($scan === null)
                                    <span class="meta">Not scanned</span>
                                @else
                                    <span class="{{ $scan->passed() ? ($scan->warnings() ? 'compat-warn' : 'compat-ok') : 'compat-fail' }}">{{ $scan->passed() ? 'Compatible' : 'Problems found' }}</span>
                                    @if ($scan->minimumCore)<br><span class="meta">Needs core {{ $scan->minimumCore }}+</span>@endif
                                    @foreach ($scan->issues as $issue)<br><span class="meta">{{ ucfirst($issue['level']) }}: {{ $issue['message'] }}</span>@endforeach
                                @endif
                            </td>
                            <td>{{ $submission->review_note }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
