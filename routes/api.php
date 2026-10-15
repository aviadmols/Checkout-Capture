<?php

use App\Http\Controllers\Api\ActivationController;
use App\Http\Controllers\Api\CaptureController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\WidgetConfigController;
use App\Http\Controllers\Shopify\AppApiController;
use App\Http\Controllers\Shopify\WebhookController as ShopifyWebhookController;
use App\Http\Middleware\AuthenticateShopifySession;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

// All Shopify webhook topics (shopify.app.toml points here): /api/shopify/webhooks
Route::post('shopify/webhooks', ShopifyWebhookController::class)
    ->middleware(VerifyShopifyWebhook::class)
    ->name('shopify.webhooks');

// Embedded app API — authenticated by App Bridge ID token; the first call installs the shop.
Route::prefix('shopify/app')
    ->middleware([AuthenticateShopifySession::class, 'throttle:shopify-app'])
    ->controller(AppApiController::class)
    ->group(function () {
        Route::get('state', 'state');
        Route::put('settings', 'updateSettings');
        Route::get('leads', 'leads');
        Route::get('leads/export-link', 'exportLink');
        Route::get('carts', 'carts');
        Route::post('billing/subscribe', 'subscribe');
        Route::post('webhook/test', 'testWebhook');
    });

Route::prefix('v1')->group(function () {
    Route::get('widget/{publicKey}', WidgetConfigController::class)
        ->middleware('throttle:widget-config')
        ->name('api.widget.config');

    Route::post('captures', [CaptureController::class, 'store'])
        ->middleware('throttle:captures')
        ->name('api.captures.store');

    Route::post('activations', [ActivationController::class, 'store'])
        ->middleware('throttle:activations')
        ->name('api.activations.store');

    Route::post('carts', [CartController::class, 'store'])
        ->middleware('throttle:carts')
        ->name('api.carts.store');
});
