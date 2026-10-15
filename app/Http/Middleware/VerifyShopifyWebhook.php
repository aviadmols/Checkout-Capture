<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shopify signs webhooks with base64(HMAC-SHA256(raw body, app secret)) in X-Shopify-Hmac-Sha256. */
class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('shopify.api_secret');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        $given = (string) $request->header('X-Shopify-Hmac-Sha256');

        if ($secret === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
