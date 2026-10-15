<?php

/*
 * Local-only mock of a Shopify storefront, for driving the widget end to end
 * without a real store: http://localhost:8000/demo
 * Loaded from bootstrap/app.php only when APP_ENV=local.
 */

use App\Http\Controllers\DemoStoreController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware(PreventRequestForgery::class)->group(function () {
    Route::get('demo', [DemoStoreController::class, 'cart']);
    Route::get('demo/reset', [DemoStoreController::class, 'reset']);
    Route::get('cart.js', [DemoStoreController::class, 'cartJson']);
    Route::post('cart/update.js', [DemoStoreController::class, 'update']);
    Route::post('cart', [DemoStoreController::class, 'submit']);
    Route::get('checkout', [DemoStoreController::class, 'checkout']);
    Route::post('api/{version}/graphql.json', [DemoStoreController::class, 'graphql']);
});
