<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fake Shopify endpoints (cart AJAX API, /checkout, Storefront GraphQL) backed by the session.
 * Local development only — see routes/demo.php.
 */
class DemoStoreController extends Controller
{
    public const DEMO_KEY = 'cc_demo_local_store';

    public function cart(Request $request): View
    {
        $shop = Shop::query()->firstOrCreate(
            ['public_key' => self::DEMO_KEY],
            [
                'name' => 'Demo Store',
                'shop_domain' => 'demo-store.myshopify.com',
                'storefront_access_token' => 'demo_storefront_token',
                'settings' => ['name_field' => 'optional', 'phone_field' => 'optional'],
            ],
        );

        return view('demo.cart', [
            'shop' => $shop,
            'cart' => $this->state($request),
            'customerEmail' => $request->query('customer'),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->session()->forget('demo_cart');

        return redirect('/demo');
    }

    public function cartJson(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    public function update(Request $request): JsonResponse
    {
        $cart = $this->state($request);
        $cart['attributes'] = array_merge($cart['attributes'], (array) $request->input('attributes', []));
        $request->session()->put('demo_cart', $cart);

        return response()->json($cart);
    }

    /** Shopify's cart form posts here with `checkout` set and gets redirected to checkout. */
    public function submit(Request $request): RedirectResponse
    {
        return redirect('/checkout');
    }

    public function checkout(Request $request): View
    {
        return view('demo.checkout', [
            'cart' => $this->state($request),
            'prefill' => $request->query('checkout')['email'] ?? null,
        ]);
    }

    public function graphql(Request $request): JsonResponse
    {
        abort_unless($request->header('X-Shopify-Storefront-Access-Token') === 'demo_storefront_token', 401);

        $cart = $this->state($request);
        $cart['buyer_identity_email'] = $request->input('variables.buyerIdentity.email');
        $request->session()->put('demo_cart', $cart);

        return response()->json(['data' => ['cartBuyerIdentityUpdate' => [
            'cart' => ['id' => $request->input('variables.cartId')],
            'userErrors' => [],
        ]]]);
    }

    private function state(Request $request): array
    {
        return $request->session()->get('demo_cart', [
            'token' => 'c1-demo'.substr(md5($request->session()->getId()), 0, 12).'?key=demo',
            'currency' => 'ILS',
            'item_count' => 3,
            'total_price' => 34700,
            'attributes' => [],
            'buyer_identity_email' => null,
            'items' => [
                ['variant_id' => 4410, 'product_title' => 'שייק חלבון', 'variant_title' => 'שוקולד', 'quantity' => 2, 'price' => 12900, 'final_price' => 12900],
                ['variant_id' => 4420, 'product_title' => 'חטיף אנרגיה', 'variant_title' => null, 'quantity' => 1, 'price' => 8900, 'final_price' => 8900],
            ],
        ]);
    }
}
