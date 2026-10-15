<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ config('shopify.api_key') }}">
    {{-- App Bridge must load first and synchronously; it signs fetch() calls with the merchant's ID token. --}}
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script src="https://cdn.shopify.com/shopifycloud/polaris-1.js"></script>
    <title>Checkout Capture</title>
    <script src="/js/shopify-app.js?v={{ filemtime(public_path('js/shopify-app.js')) }}" defer></script>
</head>
<body>
<s-app-nav>
    <s-link href="/shopify" rel="home">Home</s-link>
    <s-link href="/shopify/settings">Settings</s-link>
    <s-link href="/shopify/leads">Leads</s-link>
    <s-link href="/shopify/carts">Abandoned carts</s-link>
</s-app-nav>

@yield('content')

@stack('scripts')
</body>
</html>
