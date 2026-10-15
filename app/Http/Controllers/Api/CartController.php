<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Capture;
use App\Models\Shop;
use App\Models\TrackedCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cart activity of an identified shopper. Each call refreshes the snapshot and the
 * idle clock; `carts:detect-abandoned` marks the cart abandoned once the shop's idle
 * time passes without the shopper heading to checkout.
 */
class CartController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'source' => ['required', Rule::in([...Shop::SOURCES, 'customer'])],
            'cart' => ['required', 'array'],
            'cart.token' => ['required', 'string', 'max:255'],
            'cart.total_price' => ['nullable', 'integer', 'min:0'],
            'cart.currency' => ['nullable', 'string', 'size:3'],
            'cart.item_count' => ['required', 'integer', 'min:0'],
            'cart.items' => ['nullable', 'array', 'max:100'],
            'cart.items.*.variant_id' => ['nullable', 'integer'],
            'cart.items.*.product_title' => ['nullable', 'string', 'max:255'],
            'cart.items.*.variant_title' => ['nullable', 'string', 'max:255'],
            'cart.items.*.quantity' => ['nullable', 'integer', 'min:0'],
            'cart.items.*.price' => ['nullable', 'integer', 'min:0'],
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

        if (! $shop->resolvedSettings()['abandoned_carts']) {
            return response()->json(['ok' => true, 'tracked' => false]);
        }

        $email = Str::lower(trim($data['email']));
        $cart = $data['cart'];

        $tracked = TrackedCart::query()->firstOrNew(['shop_id' => $shop->id, 'cart_token' => $cart['token']]);

        $tracked->fill([
            'email' => $email,
            'source' => $data['source'],
            'capture_id' => Capture::query()->where('shop_id', $shop->id)->where('email', $email)->value('id'),
            'total_cents' => $cart['total_price'] ?? null,
            'currency' => isset($cart['currency']) ? Str::upper($cart['currency']) : null,
            'item_count' => $cart['item_count'],
            'items' => array_map(fn (array $item) => Arr::only($item, ['variant_id', 'product_title', 'variant_title', 'quantity', 'price']), $cart['items'] ?? []),
            'last_activity_at' => now(),
        ]);

        // Heading to checkout is final for this cart: Shopify's own abandoned checkout takes over from there.
        if ($tracked->status !== TrackedCart::CHECKOUT) {
            $tracked->status = $cart['item_count'] > 0 ? TrackedCart::ACTIVE : TrackedCart::EMPTIED;
            $tracked->abandoned_at = null;
        }

        $tracked->save();

        return response()->json(['ok' => true, 'tracked' => true, 'status' => $tracked->status]);
    }
}
