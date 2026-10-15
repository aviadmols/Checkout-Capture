<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Shopify\Billing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Embedded app pages are static shells: no shop data is rendered server-side. Each page
 * loads its data with fetch(), which App Bridge signs with the merchant's ID token.
 */
class AppPageController extends Controller
{
    public const PAGES = ['home', 'settings', 'leads', 'carts'];

    public function show(string $page = 'home'): View
    {
        abort_unless(in_array($page, self::PAGES, true), 404);

        return view("shopify.{$page}", ['page' => $page]);
    }

    /**
     * Shopify sends the merchant here (top window, outside the admin) after they approve
     * or decline the plan. We re-read the subscription and send them back into the app.
     */
    public function billingReturn(Request $request, Billing $billing): RedirectResponse
    {
        $shop = Shop::query()->where('shop_domain', Shop::normalizeHost((string) $request->query('shop')))->first();

        abort_unless($shop?->isInstalled(), 404);

        rescue(fn () => $billing->sync($shop), report: true);

        $store = Str::before($shop->shop_domain, '.myshopify.com');

        return redirect()->away("https://admin.shopify.com/store/{$store}/apps/".config('shopify.app_handle'));
    }
}
