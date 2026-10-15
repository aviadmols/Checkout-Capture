<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCaptureRequest;
use App\Models\Shop;
use App\Services\CaptureRecorder;
use Illuminate\Http\JsonResponse;

class CaptureController extends Controller
{
    public function store(StoreCaptureRequest $request, CaptureRecorder $recorder): JsonResponse
    {
        $shop = Shop::query()
            ->where('public_key', $request->validated('key'))
            ->where('mode', '!=', Shop::MODE_OFF)
            ->first();

        if (! $shop) {
            return response()->json(['message' => 'Unknown shop.'], 404);
        }

        if (! $shop->allowsOrigin($request->headers->get('Origin'))) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        // Pretend success so bots don't learn to skip the honeypot.
        if ($request->isBot()) {
            return response()->json(['ok' => true], 202);
        }

        $capture = $recorder->record($shop, $request->validated(), [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'ok' => true,
            'id' => $capture->id,
        ], $capture->wasRecentlyCreated ? 201 : 200);
    }
}
