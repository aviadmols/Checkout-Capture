<!doctype html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo cart — Checkout Capture</title>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;900&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; font-family: Heebo, system-ui, sans-serif; color: #000; background: #fff; }
        main { max-width: 720px; margin: 0 auto; padding: 8% 24px; }
        h1 { font-size: 56px; font-weight: 900; line-height: .95; margin: 0 0 40px; }
        ul { list-style: none; padding: 0; margin: 0 0 32px; border-top: 1px solid #bcbcbc; }
        li { display: flex; justify-content: space-between; padding: 16px 0; border-bottom: 1px solid #bcbcbc; }
        .actions { display: grid; gap: 16px; }
        .btn { display: block; padding: 16px; border: 1px solid #000; background: #000; color: #fff; font: inherit; font-size: 16px; text-align: center; text-decoration: none; cursor: pointer; }
        .btn--ghost { background: transparent; color: #000; }
        .terms { display: flex; gap: 8px; align-items: center; }
        .terms-error { color: #c4452d; min-height: 1.4em; margin: 0; }
        .dev { margin-top: 48px; font-size: 14px; color: #5c5c5c; }
    </style>
    <script>window.Shopify = { routes: { root: '/' } };</script>
</head>
<body>
<main>
    <h1>העגלה שלך</h1>
    <ul>
        @foreach ($cart['items'] as $item)
            <li><span>{{ $item['product_title'] }} @if($item['variant_title']) — {{ $item['variant_title'] }} @endif × {{ $item['quantity'] }}</span><span>₪{{ number_format($item['price'] * $item['quantity'] / 100, 2) }}</span></li>
        @endforeach
    </ul>

    {{-- Dawn-style cart form: <button name="checkout"> submits to /cart. --}}
    <form action="/cart" method="post" id="cart-form" class="actions">
        <label class="terms"><input type="checkbox" id="terms" checked> אני מסכים/ה לתנאי השימוש</label>
        <p class="terms-error" id="terms-error"></p>
        <button type="submit" name="checkout" class="btn" id="checkout-button">לתשלום</button>
        <a href="/checkout" class="btn btn--ghost" id="checkout-link">לתשלום (קישור)</a>
    </form>

    <p class="dev">
        Dev: <a href="/demo/reset">reset cart</a> ·
        <a href="#" onclick="CheckoutCapture.forget(); location.reload(); return false;">forget stored email</a> ·
        <a href="/demo?customer=member@example.com">as logged-in customer</a>
    </p>
</main>

<script>
    // Simulates theme JS that validates on click — the widget must replay into this, not bypass it.
    document.getElementById('checkout-button').addEventListener('click', function (event) {
        if (!document.getElementById('terms').checked) {
            event.preventDefault();
            document.getElementById('terms-error').textContent = 'יש לאשר את תנאי השימוש';
        }
    });
</script>
<script src="/js/checkout-capture.js?v={{ filemtime(public_path('js/checkout-capture.js')) }}"
        data-key="{{ $shop->public_key }}"
        @if ($customerEmail) data-customer-email="{{ $customerEmail }}" @endif
        data-storefront-api-version="2026-07"
        defer></script>
</body>
</html>
