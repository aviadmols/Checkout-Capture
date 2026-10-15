<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;

class WidgetConfigController extends Controller
{
    public function __invoke(string $publicKey): JsonResponse
    {
        $shop = Shop::query()
            ->where('public_key', $publicKey)
            ->where('is_active', true)
            ->first();

        // An unknown or paused shop gets `enabled: false` so the widget lets checkout through untouched.
        $payload = $shop
            ? ['enabled' => true, ...$shop->widgetConfig()]
            : ['enabled' => false];

        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=300');
    }
}
