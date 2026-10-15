<?php

/*
 * Local-only mock of a Shopify storefront, for driving the widget end to end
 * without a real store: http://localhost:8000/demo
 * Loaded from bootstrap/app.php only when APP_ENV=local.
 */

use App\Http\Controllers\DemoStoreController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware(PreventRequestForgery::class)->group(function () {
    Route::get('demo', [DemoStoreController::class, 'cart']);
    Route::get('demo/reset', [DemoStoreController::class, 'reset']);
    // English sample store for marketing screenshots.
    Route::get('demo/showcase', fn () => view('demo.showcase', [
        'shop' => \App\Models\Shop::where('public_key', DemoStoreController::DEMO_KEY)->firstOrFail(),
    ]));
    Route::get('cart.js', [DemoStoreController::class, 'cartJson']);
    Route::post('cart/update.js', [DemoStoreController::class, 'update']);
    Route::post('cart/add.js', [DemoStoreController::class, 'add']);
    Route::post('cart', [DemoStoreController::class, 'submit']);
    Route::get('checkout', [DemoStoreController::class, 'checkout']);
    Route::post('api/{version}/graphql.json', [DemoStoreController::class, 'graphql']);

    // Stand-in for a merchant's webhook endpoint (n8n, Make…): keeps the last delivery.
    Route::post('demo/webhook-receiver', function (Request $request) {
        Cache::put('demo_webhook_last', [
            'event' => $request->header('X-Checkout-Capture-Event'),
            'signature' => $request->header('X-Checkout-Capture-Signature'),
            'payload' => $request->json()->all(),
        ], now()->addHour());

        return ['received' => true];
    });
    Route::get('demo/webhook-receiver', fn () => Cache::get('demo_webhook_last', ['received' => false]));
});
