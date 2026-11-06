<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Marketplace\Models\Category;
use Marketplace\Models\Package;

/**
 * Publishes every bundled module and theme (catalog/, or CATALOG_ROOT) as a
 * signed release, then fills in categories and features from listings/.
 * Safe to run again: released versions are skipped and admin edits are kept.
 */
class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $root = rtrim((string) config('marketplace.source_root'), '/\\');

        if (glob($root.'/modules/*/*/module.json') === [] && glob($root.'/themes/*/*/theme.json') === []) {
            return;
        }

        @set_time_limit(0);
        Artisan::call('marketplace:publish', ['--all' => true, '--root' => $root]);
        $this->command?->getOutput()->write(Artisan::output());

        $this->applyListings();
    }

    /** listings/vendor-name.json: {"categories": [...], "features": [...]}. */
    private function applyListings(): void
    {
        foreach (glob(base_path('listings/*.json')) ?: [] as $file) {
            $listing = json_decode((string) file_get_contents($file), true);
            $identifier = preg_replace('/-/', '/', basename($file, '.json'), 1);
            $package = Package::query()->where('identifier', $identifier)->first();

            if (! is_array($listing) || $package === null) {
                continue;
            }

            $features = array_values(array_filter((array) ($listing['features'] ?? []), 'is_string'));

            if ($features !== [] && empty($package->features)) {
                $package->forceFill(['features' => $features])->save();
            }

            $slugs = array_filter((array) ($listing['categories'] ?? []), 'is_string');
            $ids = Category::query()->whereIn('slug', $slugs)->pluck('id');
            $package->categories()->syncWithoutDetaching($ids);
        }
    }
}
