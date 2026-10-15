<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\Shopify\SessionToken;
use App\Services\Shopify\ShopInstaller;
use App\Services\Shopify\TokenExchange;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Embedded app API calls: App Bridge sends `Authorization: Bearer <ID token>` on every
 * fetch() to our origin. The first verified request from a shop without a working
 * Admin API token runs token exchange + install — that is the whole install flow.
 */
class AuthenticateShopifySession
{
    public function __construct(private TokenExchange $tokens, private ShopInstaller $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        try {
            ['shop' => $domain] = SessionToken::verify($token);
        } catch (Throwable) {
            // App Bridge fetches a fresh ID token and retries once on this header.
            return response()->json(['message' => 'Invalid session.'], 401)
                ->header('X-Shopify-Retry-Invalid-Session-Request', '1');
        }

        $shop = Shop::query()->firstOrCreate(['shop_domain' => $domain], ['name' => $domain]);

        if (! $shop->isInstalled() || ($this->tokens->needsRefresh($shop) && ! $shop->shopify_refresh_token)) {
            try {
                $this->tokens->exchange($shop, $token);
                $this->installer->install($shop->refresh());
            } catch (Throwable $e) {
                Log::error("Install for {$domain} failed: {$e->getMessage()}");

                return response()->json(['message' => 'We could not finish setting up the app. Reload to try again.'], 502);
            }
        }

        $request->attributes->set('shop', $shop->refresh());

        return $next($request);
    }
}
