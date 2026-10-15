<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your cart — Northwind Goods</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, sans-serif; color: #111; background: #fff; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; border-bottom: 1px solid #eee; }
        .logo { font-weight: 600; letter-spacing: .08em; font-size: 15px; }
        nav { display: flex; gap: 20px; font-size: 14px; color: #555; }
        main { max-width: 980px; margin: 0 auto; padding: 40px 24px; display: grid; gap: 40px; }
        h1 { font-size: 32px; font-weight: 600; margin: 0; }
        .line { display: grid; grid-template-columns: 84px 1fr auto; gap: 16px; align-items: center; padding: 18px 0; border-bottom: 1px solid #eee; }
        .thumb { width: 84px; height: 100px; border-radius: 8px; }
        .t1 { background: linear-gradient(160deg, #d9cbb8, #a68b6c); }
        .t2 { background: linear-gradient(160deg, #cfd8d3, #7f948a); }
        .name { font-weight: 500; }
        .meta { color: #777; font-size: 14px; margin-top: 4px; }
        .summary { display: grid; gap: 14px; }
        .row { display: flex; justify-content: space-between; font-size: 15px; }
        .total { font-weight: 600; font-size: 18px; }
        .checkout { width: 100%; padding: 16px; border: 0; border-radius: 6px; background: #111; color: #fff; font: inherit; font-weight: 500; font-size: 16px; cursor: pointer; }
        .note { color: #888; font-size: 13px; text-align: center; }
        @media (min-width: 860px) { main { grid-template-columns: 1.5fr 1fr; align-items: start; } nav { display: flex; } }
        @media (max-width: 600px) { nav { display: none; } }
    </style>
    <script>window.Shopify = { routes: { root: '/' } };</script>
</head>
<body>
<header><span class="logo">NORTHWIND GOODS</span><nav><span>Shop</span><span>Journal</span><span>About</span><span>Cart (3)</span></nav></header>
<main>
    <section>
        <h1>Your cart</h1>
        <div class="line"><div class="thumb t1"></div><div><div class="name">Linen Overshirt</div><div class="meta">Sand · M · Qty 2</div></div><div>$178.00</div></div>
        <div class="line"><div class="thumb t2"></div><div><div class="name">Everyday Canvas Tote</div><div class="meta">Sage · Qty 1</div></div><div>$69.00</div></div>
    </section>
    <form class="summary" action="/cart" method="post">
        <div class="row"><span>Subtotal</span><span>$247.00</span></div>
        <div class="row"><span>Shipping</span><span>Calculated at checkout</span></div>
        <div class="row total"><span>Total</span><span>$247.00 USD</span></div>
        <button type="submit" name="checkout" class="checkout" id="checkout-button">Check out</button>
        <p class="note">Taxes and discounts calculated at checkout</p>
    </form>
</main>
<script src="/js/checkout-capture.js?v={{ filemtime(public_path('js/checkout-capture.js')) }}" data-key="{{ $shop->public_key }}" defer></script>
</body>
</html>
