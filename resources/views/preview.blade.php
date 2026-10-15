<!doctype html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Preview — {{ $shop->name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;900&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #000; --paper: #fff; --rule: #bcbcbc; --muted: #5c5c5c; --ease: 0.5s ease; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Heebo, system-ui, sans-serif; color: var(--ink); background: var(--paper); }
        .bar {
            position: sticky; top: 0; z-index: 10;
            display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between;
            padding: 16px 24px; border-block-end: 1px solid var(--rule); background: var(--paper);
        }
        .bar-title { margin: 0; font-size: 14px; font-weight: 500; letter-spacing: 2px; }
        .bar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .btn {
            display: inline-block; padding: 10px 18px; border: 1px solid var(--ink); border-radius: 0;
            background: transparent; color: var(--ink); font: inherit; font-size: 14px; font-weight: 500;
            text-decoration: none; cursor: pointer; transition: all var(--ease);
        }
        .btn:hover, .btn[aria-current="true"] { background: var(--ink); color: var(--paper); }
        main { max-width: 1024px; margin: 0 auto; padding: 8% 24px; }
        .eyebrow { margin: 0 0 16px; font-size: 14px; font-weight: 500; letter-spacing: 2px; color: var(--muted); }
        h1 { margin: 0 0 24px; font-size: 44px; line-height: .95; }
        h1 .light { display: block; font-weight: 300; }
        h1 .black { font-weight: 900; }
        p { max-width: 520px; font-size: 18px; font-weight: 300; line-height: 1.5; color: var(--muted); }
        @media (min-width: 1024px) { h1 { font-size: 96px; } }
    </style>
</head>
<body>
<header class="bar">
    <p class="bar-title">PREVIEW · {{ $shop->name }}</p>
    <nav class="bar-actions" aria-label="Design">
        <a class="btn" href="?design=classic" @if($design === 'classic') aria-current="true" @endif>Classic</a>
        <a class="btn" href="?design=split" @if($design === 'split') aria-current="true" @endif>Split</a>
        <button type="button" class="btn" onclick="CheckoutCapture.open()">Open popup</button>
    </nav>
</header>
<main>
    <p class="eyebrow">תצוגה מקדימה</p>
    <h1><span class="light">ככה זה ייראה</span><span class="black">בחנות שלך</span></h1>
    <p>הפופאפ מוצג עם ההגדרות השמורות של החנות. בתצוגה המקדימה שום דבר לא נשמר ולא נשלח ל-Shopify. כדי לראות שינויים, שומרים בפאנל ומרעננים את הדף.</p>
</main>

<script type="application/json" id="checkout-capture-config">@json($config)</script>
<script src="/js/checkout-capture.js?v={{ filemtime(public_path('js/checkout-capture.js')) }}" data-key="{{ $shop->public_key }}" data-preview="true"></script>
<script>CheckoutCapture.open();</script>
</body>
</html>
