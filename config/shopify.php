<?php

return [
    // From the app's page in the Shopify Partner Dashboard (Client ID / Client secret).
    'api_key' => env('SHOPIFY_API_KEY'),
    'api_secret' => env('SHOPIFY_API_SECRET'),

    'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),

    // Must match [access_scopes] in shopify.app.toml.
    'scopes' => env('SHOPIFY_SCOPES', 'read_orders,read_customers,write_customers,unauthenticated_read_product_listings,unauthenticated_read_checkouts,unauthenticated_write_checkouts'),

    // Charges are created as test charges (no real money) — keep true until the app is approved.
    'billing_test' => (bool) env('SHOPIFY_BILLING_TEST', true),

    // App embed block file name (blocks/app-embed.liquid) — used for the theme-editor deep link.
    'embed_handle' => env('SHOPIFY_EMBED_HANDLE', 'app-embed'),

    // App handle from shopify.app.toml — admin URL: admin.shopify.com/store/{store}/apps/{handle}.
    'app_handle' => env('SHOPIFY_APP_HANDLE', 'checkout-capture'),

    // Metafield namespace the app writes on its own installation; the theme app embed reads it.
    'metafield_namespace' => 'checkout_capture',
];
