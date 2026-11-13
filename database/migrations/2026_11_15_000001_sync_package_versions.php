<?php

use Illuminate\Database\Migrations\Migration;
use Marketplace\Models\Package;
use Marketplace\Services\Releases;

/**
 * Listings whose version fell behind their newest release (an older bundled
 * version was published after an uploaded update) show the newest one again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $releases = app(Releases::class);

        Package::query()->whereHas('releases')->each(fn (Package $package) => $releases->syncListing($package));
    }

    public function down(): void {}
};
