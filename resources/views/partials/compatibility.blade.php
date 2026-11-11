{{-- Compatibility scan result for a submission. Expects $report (CompatibilityReport|null). --}}
<section class="compat" aria-label="Compatibility scan">
    <h3>Compatibility scan</h3>
    @if ($report === null)
        <p class="meta">Not scanned (submitted before scanning was added). Rescan it before approving.</p>
    @else
        @php($errors_ = $report->errors())
        @php($warnings = $report->warnings())
        <p class="{{ $errors_ ? 'compat-fail' : ($warnings ? 'compat-warn' : 'compat-ok') }}">
            @if ($errors_)
                {{ count($errors_) }} {{ \Illuminate\Support\Str::plural('problem', count($errors_)) }} would stop stores from installing it.
            @elseif ($warnings)
                Compatible, with {{ count($warnings) }} {{ \Illuminate\Support\Str::plural('note', count($warnings)) }}.
            @else
                Compatible.
            @endif
        </p>
        <dl class="package-specs">
            <div><dt>Platform version</dt><dd>{{ $report->platformVersion ?? 'Not set' }}</dd></div>
            <div><dt>Needs core</dt><dd>{{ $report->minimumCore ? $report->minimumCore.' or later' : 'Any' }}@if ($report->uses) <span class="meta">({{ implode(', ', array_keys($report->uses)) }})</span>@endif</dd></div>
            <div><dt>First published core it runs on</dt><dd>{{ $report->firstCore ?? 'None yet' }}@if ($report->latestCore) <span class="meta">· latest is {{ $report->latestCore }}</span>@endif</dd></div>
            @if ($report->requires)
                <div><dt>Requires</dt><dd>@foreach ($report->requires as $identifier => $constraint){{ $identifier }} {{ $constraint }}@if (! $loop->last), @endif @endforeach</dd></div>
            @endif
        </dl>
        @if ($report->issues)
            <ul>
                @foreach ($report->issues as $issue)
                    <li class="issue-{{ $issue['level'] }}"><strong>{{ ucfirst($issue['level']) }}:</strong> {{ $issue['message'] }}</li>
                @endforeach
            </ul>
        @endif
        @if ($report->scannedAt)
            <p class="meta">Scanned {{ \Illuminate\Support\Carbon::parse($report->scannedAt)->format('Y-m-d H:i') }}</p>
        @endif
    @endif
</section>
