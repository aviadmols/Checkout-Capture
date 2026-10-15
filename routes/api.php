<?php

use App\Http\Controllers\Api\CaptureController;
use App\Http\Controllers\Api\WidgetConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('widget/{publicKey}', WidgetConfigController::class)
        ->middleware('throttle:widget-config')
        ->name('api.widget.config');

    Route::post('captures', [CaptureController::class, 'store'])
        ->middleware('throttle:captures')
        ->name('api.captures.store');
});
