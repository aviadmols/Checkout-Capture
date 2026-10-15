<?php

use App\Http\Controllers\ShopPreviewController;
use App\Http\Controllers\Shopify\AppApiController;
use App\Http\Controllers\Shopify\AppPageController;
use App\Http\Middleware\ShopifyFrameAncestors;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('preview/{shop}', ShopPreviewController::class)
    ->middleware('auth')
    ->name('shops.preview');

// Same preview for merchants: the embedded app hands out an hour-long signed link.
Route::get('preview/{shop}/signed', ShopPreviewController::class)
    ->middleware('signed')
    ->name('shops.preview.signed');

Route::get('shopify/leads/export/{shop}', [AppApiController::class, 'exportLeads'])
    ->middleware('signed')
    ->name('shopify.leads.export');

// Embedded Shopify app (application_url = APP_URL/shopify). Pages are shells; data comes
// from /api/shopify/app/* with App Bridge's ID token.
Route::middleware(ShopifyFrameAncestors::class)->group(function () {
    Route::get('shopify/billing/return', [AppPageController::class, 'billingReturn'])->name('shopify.billing.return');
    Route::get('shopify/{page?}', [AppPageController::class, 'show'])
        ->whereIn('page', AppPageController::PAGES)
        ->name('shopify.page');
});
