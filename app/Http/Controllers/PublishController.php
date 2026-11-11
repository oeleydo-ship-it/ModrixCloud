<?php

namespace Marketplace\Http\Controllers;

use Composer\Semver\Comparator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Marketplace\Models\Package;
use Marketplace\Models\Submission;
use Marketplace\Services\CompatibilityReport;
use Marketplace\Services\CompatibilityScanner;
use Marketplace\Services\Uploads;

/** Publishers send new packages and updates for review. */
class PublishController
{
    public const IDENTIFIER = '/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/';

    public const VERSION = '/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/';

    public function create(): View
    {
        return view('publish.create');
    }

    public function store(Request $request, Uploads $uploads, CompatibilityScanner $scanner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'identifier' => ['required', 'string', 'max:128', 'regex:'.self::IDENTIFIER, 'unique:packages,identifier'],
            'type' => ['required', 'in:theme,module'],
            'version' => ['required', 'string', 'max:32', 'regex:'.self::VERSION],
            'platform_version' => ['nullable', 'string', 'max:64'],
            'author' => ['required', 'string', 'max:120'],
            'license_name' => ['required', 'string', 'max:64'],
            'summary' => ['required', 'string', 'max:255'],
            'tier' => ['required', 'in:free,premium'],
            'price' => ['required_if:tier,premium', 'nullable', 'numeric', 'min:0', 'max:99999'],
            'demo_url' => ['nullable', 'url:https', 'max:500'],
            'package' => ['required', 'file'],
        ], ['demo_url.url' => 'The demo URL must start with https://.']);

        if ($data['tier'] === 'premium' && (float) $data['price'] <= 0) {
            throw ValidationException::withMessages(['price' => 'Set a price above zero for a paid package.']);
        }

        if (Submission::query()->where('identifier', $data['identifier'])->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['identifier' => 'This package is already waiting for review.']);
        }

        $zip = $uploads->packageZip($request->file('package'), $data['identifier'], $data['type'], $data['version']);
        $report = $this->scan($scanner, $uploads, $zip, $data['type'], $data['platform_version'] ?? null);
        unset($data['package']);
        $data['platform_version'] = ($data['platform_version'] ?? '') ?: $report->platformVersion;
        $data['compatibility'] = $report->toArray();

        Submission::query()->create(['user_id' => $request->user()->id, 'identifier' => $data['identifier'], 'kind' => 'new', 'data' => $data, 'zip_path' => $zip]);

        return redirect('/submissions')->with('notice', 'Thanks! Your package is waiting for review.');
    }

    public function edit(Request $request): View
    {
        // The publisher's own packages plus marketplace-owned ones (no publisher), which anyone may propose updates for.
        $packages = Package::query()
            ->where(fn ($q) => $q->where('publisher_id', $request->user()->id)->orWhereNull('publisher_id'))
            ->with('releases')
            ->orderBy('type')->orderBy('name')
            ->get();

        return view('publish.update', ['packages' => $packages]);
    }

    public function update(Request $request, Uploads $uploads, CompatibilityScanner $scanner): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:128'],
            'version' => ['required', 'string', 'max:32', 'regex:'.self::VERSION],
            'platform_version' => ['nullable', 'string', 'max:64'],
            'changelog' => ['required', 'string', 'max:5000'],
            'package' => ['required', 'file'],
        ]);

        $package = Package::query()->where('identifier', $data['identifier'])->first();

        // Packages without a publisher were added by the marketplace; anyone may propose an update for review.
        if ($package === null) {
            throw ValidationException::withMessages(['identifier' => 'That package is not in the marketplace yet. Use Submit a package to add it first.']);
        }

        if (($package->publisher_id !== null && $package->publisher_id !== $request->user()->id)) {
            throw ValidationException::withMessages(['identifier' => 'Choose one of your published packages.']);
        }

        if (Submission::query()->where('identifier', $package->identifier)->where('kind', 'update')->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['identifier' => 'An update for this package is already waiting for review.']);
        }

        $current = $package->latestRelease()?->version ?? $package->version;

        if (! Comparator::greaterThan($data['version'], $current)) {
            throw ValidationException::withMessages(['version' => "The new version must be higher than {$current}."]);
        }

        $zip = $uploads->packageZip($request->file('package'), $package->identifier, $package->type, $data['version']);
        $report = $this->scan($scanner, $uploads, $zip, $package->type, $data['platform_version'] ?? null);
        unset($data['package']);
        $data['platform_version'] = ($data['platform_version'] ?? '') ?: ($report->platformVersion ?? $package->platform_version);
        $data['compatibility'] = $report->toArray();

        Submission::query()->create(['user_id' => $request->user()->id, 'identifier' => $package->identifier, 'kind' => 'update', 'data' => $data, 'zip_path' => $zip]);

        return redirect('/submissions')->with('notice', 'Thanks! Your update is waiting for review.');
    }

    /**
     * Scans the stored ZIP. A package that would fail on stores is refused
     * with every problem listed, and its file is removed.
     *
     * @throws ValidationException on `package`
     */
    private function scan(CompatibilityScanner $scanner, Uploads $uploads, string $zip, string $type, ?string $platform): CompatibilityReport
    {
        $report = $scanner->scan($uploads->path($zip), $type, $platform);

        if (! $report->passed()) {
            @unlink($uploads->path($zip));

            throw ValidationException::withMessages(['package' => array_map(
                fn (string $message) => 'Compatibility: '.$message,
                $report->errors(),
            )]);
        }

        return $report;
    }

    public function index(Request $request): View
    {
        return view('publish.index', [
            'submissions' => Submission::query()->where('user_id', $request->user()->id)->latest()->get(),
        ]);
    }
}
