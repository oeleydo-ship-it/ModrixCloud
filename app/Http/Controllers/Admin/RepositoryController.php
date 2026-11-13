<?php

namespace Marketplace\Http\Controllers\Admin;

use Database\Seeders\PackageSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Marketplace\Http\Controllers\PublishController;
use Marketplace\Models\Category;
use Marketplace\Models\License;
use Marketplace\Models\Order;
use Marketplace\Models\Package;
use Marketplace\Models\PackageRelease;
use Marketplace\Models\Submission;
use Marketplace\Models\Subscription;
use Marketplace\Services\Releases;
use Marketplace\Services\Submissions;
use Marketplace\Services\Subscriptions;
use Marketplace\Services\Uploads;

/** Admin overview and the package repository: listings, releases, categories, prices. */
class RepositoryController
{
    /** How a paid package is offered. */
    public const SALE_MODES = ['individual', 'subscription', 'both'];

    public function overview(): View
    {
        return view('admin.overview', [
            'stats' => [
                'Published packages' => Package::query()->where('status', 'published')->count(),
                'Pending submissions' => Submission::query()->where('status', 'pending')->count(),
                'Active licenses' => License::query()->where('status', 'active')->count(),
                'Paid orders' => Order::query()->where('status', 'paid')->count(),
                'Active subscriptions' => Subscription::query()->live()->count(),
            ],
            'recent' => Submission::query()->with('user')->latest()->limit(5)->get(),
        ]);
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('admin.repository.index', [
            'q' => $q,
            'bundled' => $this->bundledStatus(),
            'packages' => Package::query()
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('identifier', 'like', "%{$q}%")))
                ->orderBy('name')->paginate(30)->withQueryString(),
        ]);
    }

    /**
     * Publishes every module and theme bundled with the app (catalog/ or
     * CATALOG_ROOT) that is not released yet, then applies listings/. The
     * same as `php artisan db:seed --class=PackageSeeder` on the server.
     */
    public function publishBundled(): RedirectResponse
    {
        $before = PackageRelease::query()->count();
        Artisan::call('db:seed', ['--class' => PackageSeeder::class, '--force' => true]);
        $added = PackageRelease::query()->count() - $before;
        $missing = $this->bundledStatus()['missing'];

        return redirect('/admin/repository')->with('notice', $missing === []
            ? "Bundled packages are up to date ({$added} new releases published)."
            : "{$added} new releases published; ".count($missing).' could not be published: '.implode(', ', $missing).'. See the log for details.');
    }

    /**
     * Bundled packages and the ones whose bundled version is not released yet.
     *
     * @return array{total: int, missing: list<string>}
     */
    private function bundledStatus(): array
    {
        $root = rtrim((string) config('marketplace.source_root'), '/\\');
        $wanted = [];

        foreach (['module' => 'modules', 'theme' => 'themes'] as $type => $folder) {
            foreach (glob($root.'/'.$folder.'/*/*/'.$type.'.json') ?: [] as $file) {
                $manifest = json_decode((string) file_get_contents($file), true);

                if (is_array($manifest) && is_string($manifest['name'] ?? null)) {
                    $wanted[$manifest['name']] = (string) ($manifest['version'] ?? '');
                }
            }
        }

        $released = PackageRelease::query()
            ->join('packages', 'packages.id', '=', 'package_releases.package_id')
            ->whereIn('packages.identifier', array_keys($wanted))
            ->get(['packages.identifier', 'package_releases.version'])
            ->map(fn ($r) => $r->identifier.'@'.$r->version)
            ->flip();

        $missing = array_keys(array_filter($wanted, fn ($version, $identifier) => ! isset($released[$identifier.'@'.$version]), ARRAY_FILTER_USE_BOTH));
        sort($missing);

        return ['total' => count($wanted), 'missing' => $missing];
    }

    public function create(): View
    {
        return view('admin.repository.edit', ['package' => new Package(['status' => 'draft', 'is_free' => true, 'version' => '1.0.0', 'platform_version' => '^1.0', 'license_name' => 'MIT']), 'categories' => Category::query()->orderBy('position')->get(), 'releases' => collect()]);
    }

    public function store(Request $request, Uploads $uploads, Releases $releases): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:128', 'regex:'.PublishController::IDENTIFIER, 'unique:packages,identifier'],
            'type' => ['required', 'in:theme,module'],
            'package' => ['required', 'file'],
            ...$this->listingRules(),
        ]);

        $zip = $uploads->packageZip($request->file('package'), $data['identifier'], $data['type'], $data['version']);

        $package = DB::transaction(function () use ($request, $data, $uploads, $releases, $zip) {
            $package = new Package(['identifier' => $data['identifier'], 'type' => $data['type'], 'slug' => Submissions::slugFor($data['identifier']), 'currency' => (string) config('marketplace.currency')]);
            $this->fill($request, $package, $data, $uploads);
            $releases->publish($package, $uploads->path($zip), $data['version'], $package->platform_version);

            return $package;
        });

        return redirect('/admin/repository/'.$package->slug)->with('notice', 'Package added.');
    }

    public function edit(string $slug): View
    {
        $package = Package::query()->where('slug', $slug)->with('categories')->firstOrFail();

        return view('admin.repository.edit', [
            'package' => $package,
            'categories' => Category::query()->orderBy('position')->get(),
            'releases' => $package->releases()->latest('published_at')->get(),
        ]);
    }

    public function update(Request $request, string $slug, Uploads $uploads, Releases $releases): RedirectResponse
    {
        $package = Package::query()->where('slug', $slug)->firstOrFail();
        $data = $request->validate([...$this->listingRules(), 'package' => ['nullable', 'file']]);

        // A ZIP uploaded here is published as the release for the version field.
        $zip = $request->hasFile('package') ? $uploads->packageZip($request->file('package'), $package->identifier, $package->type, $data['version']) : null;

        DB::transaction(function () use ($request, $package, $data, $uploads, $releases, $zip) {
            $this->fill($request, $package, $data, $uploads);

            if ($zip !== null) {
                $releases->publish($package, $uploads->path($zip), $data['version'], $package->platform_version);

                return;
            }

            // A changed platform version applies to the release for the version field,
            // so stores on an older core stop being offered it.
            $release = $package->releases()->where('version', $package->version)->first();

            if ($release !== null && $release->platform_version !== $package->platform_version) {
                $releases->setPlatformVersion($release, $package->platform_version);
            }
        });

        return redirect('/admin/repository/'.$package->slug)->with('notice', 'Package saved.');
    }

    /**
     * Deletes a package with its releases, categories and licenses, and removes
     * its stored files. A package with orders is kept, so sales records and
     * buyers' licenses survive: hide it instead.
     */
    public function destroy(string $slug, Releases $releases, Uploads $uploads): RedirectResponse
    {
        $package = Package::query()->where('slug', $slug)->firstOrFail();

        if (Order::query()->where('package_id', $package->id)->exists()) {
            return back()->withErrors(['delete' => $package->name.' has orders, so it cannot be deleted. Set its status to Hidden to remove it from the catalog.']);
        }

        $files = $package->releases()->get()->map(fn (PackageRelease $release) => $releases->path($release))->all();

        if ($package->thumbnail_path) {
            $files[] = $uploads->path($package->thumbnail_path);
        }

        DB::transaction(fn () => $package->delete());

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        return redirect('/admin/repository')->with('notice', $package->name.' ('.$package->identifier.') was deleted.');
    }

    /** Release history across packages. */
    public function updates(): View
    {
        return view('admin.updates', [
            'pending' => Submission::query()->where('kind', 'update')->where('status', 'pending')->with('user')->latest()->get(),
            'releases' => PackageRelease::query()->with('package')->latest('published_at')->limit(100)->get(),
        ]);
    }

    /** Prices for every package on one page. */
    public function pricing(Subscriptions $subscriptions): View
    {
        return view('admin.pricing', [
            'packages' => Package::query()->orderBy('name')->get(),
            'plan' => $subscriptions->plan(),
            'subscribers' => Subscription::query()->live()->count(),
        ]);
    }

    public function savePricing(Request $request, Subscriptions $subscriptions): RedirectResponse
    {
        $data = $request->validate([
            'prices' => ['array'],
            'prices.*.tier' => ['required', 'in:free,premium'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'prices.*.sale' => ['nullable', 'in:'.implode(',', self::SALE_MODES)],
        ]);

        foreach ($data['prices'] ?? [] as $id => $row) {
            $package = Package::query()->find((int) $id);

            if ($package instanceof Package) {
                $package->forceFill(self::priceColumns($row['tier'], $row['price'] ?? null, $row['sale'] ?? $package->saleMode()))->save();
                $subscriptions->syncPackage($package);
            }
        }

        return redirect('/admin/catalog')->with('notice', 'Prices saved.');
    }

    /**
     * Price columns from the admin forms. A paid package is sold individually
     * (needs a price), included in the subscription (price optional), or both.
     * A paid package sold individually at 0 is free, as before.
     *
     * @return array{is_free: bool, price: mixed, sold_individually: bool, in_subscription: bool}
     */
    public static function priceColumns(string $tier, mixed $price, string $sale): array
    {
        $price = (float) ($price ?? 0) > 0 ? $price : 0;
        $sale = in_array($sale, self::SALE_MODES, true) ? $sale : 'individual';

        if ($tier !== 'premium' || ($price === 0 && $sale === 'individual')) {
            return ['is_free' => true, 'price' => 0, 'sold_individually' => true, 'in_subscription' => false];
        }

        // Without a price a package cannot be bought on its own.
        $individual = $price !== 0 && $sale !== 'subscription';

        return ['is_free' => false, 'price' => $price, 'sold_individually' => $individual, 'in_subscription' => $sale !== 'individual'];
    }

    /** @return array<string, list<mixed>> */
    private function listingRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'tier' => ['required', 'in:free,premium'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'sale' => ['nullable', 'in:'.implode(',', self::SALE_MODES)],
            'status' => ['required', 'in:draft,published,hidden'],
            'version' => ['required', 'string', 'max:32', 'regex:'.PublishController::VERSION],
            'platform_version' => ['nullable', 'string', 'max:64'],
            'author' => ['nullable', 'string', 'max:120'],
            'license_name' => ['nullable', 'string', 'max:64'],
            'demo_url' => ['nullable', 'url:https', 'max:500'],
            'repository_url' => ['nullable', 'url:http,https', 'max:500'],
            'changelog' => ['nullable', 'string', 'max:5000'],
            'categories' => ['array'],
            'categories.*' => ['string', 'exists:categories,slug'],
            'features_text' => ['nullable', 'string', 'max:5000'],
            'featured' => ['nullable', 'boolean'],
            'thumbnail' => ['nullable', 'file'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function fill(Request $request, Package $package, array $data, Uploads $uploads): void
    {
        $pricing = self::priceColumns($data['tier'], $data['price'] ?? null, $data['sale'] ?? $package->saleMode());
        $features = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['features_text'] ?? '')) ?: []), fn ($f) => $f !== ''));

        $package->fill([
            'name' => $data['name'],
            'summary' => $data['summary'],
            'description' => $data['description'] ?? $package->description,
            ...$pricing,
            'status' => $data['status'],
            'version' => $data['version'],
            'platform_version' => ($data['platform_version'] ?? '') ?: ($package->platform_version ?: '^1.0'),
            'author' => (string) ($data['author'] ?? $package->author ?? ''),
            'license_name' => ($data['license_name'] ?? '') ?: ($package->license_name ?: 'MIT'),
            'demo_url' => $data['demo_url'] ?? null,
            'repository_url' => $data['repository_url'] ?? null,
            'changelog' => $data['changelog'] ?? $package->changelog,
            'features' => $features,
            'featured' => $request->boolean('featured'),
        ]);

        if ($request->hasFile('thumbnail')) {
            $package->thumbnail_path = $uploads->image($request->file('thumbnail'), 'thumbnails', 'thumbnail');
        }

        $package->save();
        $package->categories()->sync(Category::query()->whereIn('slug', $data['categories'] ?? [])->pluck('id'));
        app(Subscriptions::class)->syncPackage($package);
    }
}
