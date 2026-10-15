<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activation;
use App\Models\Capture;
use App\Models\Shop;
use App\Models\TrackedCart;
use App\Support\UsagePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Records a billable activation: the widget attached an email to a cart and sent the
 * shopper to checkout. Repeats for the same cart in the same month are not counted again.
 */
class ActivationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'source' => ['required', Rule::in(Shop::SOURCES)],
            'cart_token' => ['nullable', 'string', 'max:255'],
        ]);

        $shop = Shop::query()
            ->where('public_key', $data['key'])
            ->where('mode', '!=', Shop::MODE_OFF)
            ->first();

        if (! $shop) {
            return response()->json(['message' => 'Unknown shop.'], 404);
        }

        if (! $shop->allowsOrigin($request->headers->get('Origin'))) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        $email = Str::lower(trim($data['email']));
        $cartToken = $data['cart_token'] ?? null;
        $now = now();

        $counted = Activation::query()->insertOrIgnore([
            'shop_id' => $shop->id,
            'capture_id' => Capture::query()->where('shop_id', $shop->id)->where('email', $email)->value('id'),
            'email' => $email,
            'cart_token' => $cartToken,
            'source' => $data['source'],
            'period' => UsagePlan::period($now),
            'dedupe_key' => hash('sha256', $cartToken ? "cart:{$cartToken}" : "email:{$email}"),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The shopper is on the way to checkout — this cart is no longer a candidate for abandonment.
        if ($cartToken) {
            TrackedCart::query()
                ->where('shop_id', $shop->id)
                ->where('cart_token', $cartToken)
                ->update(['status' => TrackedCart::CHECKOUT, 'abandoned_at' => null]);
        }

        return response()->json(['ok' => true, 'counted' => $counted > 0], $counted ? 201 : 200);
    }
}
