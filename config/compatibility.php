<?php

/*
 * What the Modrix Commerce core accepts in packages, and the first core
 * version that supports each feature. The compatibility scanner checks
 * submitted packages against this. When a new core adds a storefront place,
 * slot, capability or manifest key, add it here with that core's version.
 */

$rc1 = '1.0.0-rc.1';

return [
    // Core versions that have shipped, oldest first. A package whose
    // platform_version accepts an older one than its features need is refused.
    'core_versions' => ['1.0.0-rc.1', '1.0.0-rc.2', '1.0.0-rc.3'],

    // Places where merchants can move extension blocks (StorefrontSlots::PLACES).
    'places' => [
        'header.actions' => $rc1,
        'home.after_featured' => $rc1,
        'catalog.before_products' => $rc1,
        'catalog.after_products' => $rc1,
        'product.card' => $rc1,
        'product.after_title' => $rc1,
        'product.after_price' => $rc1,
        'product.after_add_to_cart' => $rc1,
        'product.after_details' => $rc1,
        'product.after_description' => $rc1,
        'cart.after_lines' => $rc1,
        'cart.after_totals' => $rc1,
        'checkout.after_shipping' => '1.0.0-rc.3',
        'checkout.after_summary' => $rc1,
        'checkout.before_place_order' => $rc1,
        'storefront.footer' => $rc1,
    ],

    // Fixed slots (StorefrontSlots::SLOTS). register() accepts these and places.
    'slots' => [
        'home.after_featured' => $rc1,
        'catalog.after_products' => $rc1,
        'product.after_details' => $rc1,
        'admin.product.sidebar' => $rc1,
        'order.confirmation' => $rc1,
        'account.order' => $rc1,
        'cart.after_totals' => $rc1,
        'checkout.payment_method' => $rc1,
        'checkout.after_summary' => $rc1,
        'checkout.after_shipping' => '1.0.0-rc.3',
        'storefront.overlay' => $rc1,
        'storefront.footer' => $rc1,
    ],

    'module_capabilities' => array_fill_keys([
        'admin.routes', 'admin.navigation', 'storefront.routes', 'storefront.slots', 'dashboard.widgets',
        'database.migrations', 'settings', 'permissions', 'events.listen', 'scheduled.tasks', 'console.commands',
        'notifications', 'checkout.validators', 'pricing.steps', 'catalog.sale_prices', 'catalog.image_urls',
        'payment.gateway', 'shipping.rates', 'tax.provider', 'search.provider', 'sitemap.provider',
        'product.labels', 'content_security',
    ], $rc1),

    'theme_capabilities' => array_fill_keys([
        'home.sections', 'custom_fonts', 'dark_mode', 'demo_content', 'sections',
    ], $rc1),

    // Manifest keys the core reads; any other key makes the store refuse the package.
    'module_keys' => array_fill_keys([
        '$schema', 'name', 'display_name', 'description', 'version', 'type', 'platform_version', 'author', 'license',
        'autoload', 'provider', 'requires', 'permissions', 'capabilities', 'migrations', 'routes', 'assets',
        'settings', 'admin_navigation', 'content_security', 'icon', 'accent', 'logo',
    ], $rc1),

    'theme_keys' => array_fill_keys([
        '$schema', 'name', 'display_name', 'description', 'version', 'type', 'theme_type', 'platform_version',
        'author', 'license', 'requires', 'capabilities', 'assets', 'screenshot', 'design', 'fonts', 'dark_colors',
        'sections', 'demo_content', 'changelog', 'customizer', 'customizer_file', 'frontend',
    ], $rc1),
];
