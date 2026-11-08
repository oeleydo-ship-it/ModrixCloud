<?php

return [
    // Catalog prices are shown in this currency unless changed in admin settings.
    'currency' => env('MARKETPLACE_CURRENCY', 'USD'),

    // Private files: package ZIPs, release archives, uploads. Never public.
    'storage' => env('MARKETPLACE_STORAGE', storage_path('app/private')),

    // Written when setup finishes (web installer or marketplace:install); /install is closed while it exists.
    'installed_file' => storage_path('app/installed'),

    // Recovery: reopens /install on an installed marketplace so the owner can set an
    // administrator's password again (guarded by the database password). Remove after use.
    'reopen_installer' => (bool) env('MARKETPLACE_REOPEN_INSTALLER', false),

    // Source tree (modules/ and themes/) that `marketplace:publish` packages from:
    // the bundled catalog/ folder, else the folder above this app (the platform repository).
    'source_root' => env('CATALOG_ROOT') ?: (is_dir(base_path('catalog/modules')) ? base_path('catalog') : dirname(base_path())),

    // Signed license tokens stay valid this long; stores refresh them on checks.
    'token_days' => 30,

    // One-time download links expire after this many minutes.
    'download_minutes' => 10,

    // Largest core platform release ZIP accepted in admin, in megabytes.
    'core_max_mb' => (int) env('MARKETPLACE_CORE_MAX_MB', 200),
];
