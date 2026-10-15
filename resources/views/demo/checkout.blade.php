<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mock checkout — Checkout Capture</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; }
        main { max-width: 720px; margin: 0 auto; padding: 48px 24px; }
        dt { font-weight: 600; margin-top: 16px; }
        dd { margin: 4px 0 0; font-family: ui-monospace, monospace; }
    </style>
</head>
<body>
<main>
    <h1 id="checkout-title">Mock Shopify checkout</h1>
    <dl>
        <dt>?checkout[email] prefill</dt>
        <dd id="prefill">{{ $prefill ?? '—' }}</dd>
        <dt>Cart attributes</dt>
        <dd id="attributes">{{ json_encode($cart['attributes'], JSON_UNESCAPED_UNICODE) }}</dd>
        <dt>Buyer identity email (Storefront API)</dt>
        <dd id="buyer-identity">{{ $cart['buyer_identity_email'] ?? '—' }}</dd>
    </dl>
    <p><a href="/demo">← back to cart</a></p>
</main>
</body>
</html>
