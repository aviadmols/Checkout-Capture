<?php

use App\Http\Controllers\ShopPreviewController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('preview/{shop}', ShopPreviewController::class)
    ->middleware('auth')
    ->name('shops.preview');
