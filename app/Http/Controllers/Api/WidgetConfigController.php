<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Shopify\Billing;
use Illuminate\Http\JsonResponse;

class WidgetConfigController extends Controller
{
    public function __invoke(string $publicKey, Billing $billing): JsonResponse
    {
        $shop = Shop::query()
            ->where('public_key', $publicKey)
            ->where('mode', '!=', Shop::MODE_OFF)
            ->first();

        if ($shop && (! $shop->widget_seen_at || $shop->widget_seen_at->lt(now()->subMinutes(10)))) {
            // Lets the merchant's app home show that the theme app embed is live.
            $shop->forceFill(['widget_seen_at' => now()])->saveQuietly();
        }

        // Unknown, switched off, or past the free tier without an approved plan:
        // `enabled: false` makes the widget step aside and checkout works untouched.
        $payload = $shop && $billing->canServe($shop)
            ? ['enabled' => true, ...$shop->widgetConfig()]
            : ['enabled' => false];

        return response()
            ->json($payload)
            ->header('Cache-Control', 'public, max-age=60');
    }
}
