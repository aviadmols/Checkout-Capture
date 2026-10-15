<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Embedded pages may only be framed by the merchant's admin (Shopify App Store requirement). */
class ShopifyFrameAncestors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $shop = (string) $request->query('shop');
        $shopOrigin = preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop) ? "https://{$shop}" : 'https://*.myshopify.com';

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$shopOrigin} https://admin.shopify.com;");

        return $response;
    }
}
