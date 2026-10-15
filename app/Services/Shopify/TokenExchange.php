<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shopify-managed install: the embedded app's ID token is exchanged for an expiring
 * offline Admin API token (1 hour) plus a refresh token (90 days).
 * https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/token-exchange
 */
class TokenExchange
{
    public function exchange(Shop $shop, string $idToken): void
    {
        $this->store($shop, $this->request($shop, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $idToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            'expiring' => 1,
        ]));
    }

    /**
     * Swaps the refresh token for a new pair. One refresh per shop at a time — Shopify
     * invalidates the old refresh token, so a parallel refresh would lose the race.
     */
    public function refresh(Shop $shop): void
    {
        Cache::lock("shopify-refresh:{$shop->id}", 30)->block(20, function () use ($shop) {
            $shop->refresh();

            if (! $this->needsRefresh($shop)) {
                return; // another request refreshed it while we waited
            }

            if (! $shop->shopify_refresh_token || $shop->shopify_refresh_expires_at?->isPast()) {
                throw new RuntimeException("Shop {$shop->shop_domain} must open the app again to renew access.");
            }

            $this->store($shop, $this->request($shop, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $shop->shopify_refresh_token,
            ]));
        });
    }

    public function needsRefresh(Shop $shop): bool
    {
        return ! $shop->shopify_access_token
            || ($shop->shopify_token_expires_at && $shop->shopify_token_expires_at->lt(now()->addMinutes(5)));
    }

    private function request(Shop $shop, array $grant): array
    {
        $response = Http::asForm()->timeout(15)->post("https://{$shop->shop_domain}/admin/oauth/access_token", [
            'client_id' => config('shopify.api_key'),
            'client_secret' => config('shopify.api_secret'),
            ...$grant,
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException("Token request for {$shop->shop_domain} failed: HTTP {$response->status()} ".mb_substr($response->body(), 0, 200));
        }

        return $response->json();
    }

    private function store(Shop $shop, array $token): void
    {
        $shop->forceFill([
            'shopify_access_token' => $token['access_token'],
            'shopify_scopes' => $token['scope'] ?? $shop->shopify_scopes,
            'shopify_token_expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
            'shopify_refresh_token' => $token['refresh_token'] ?? null,
            'shopify_refresh_expires_at' => isset($token['refresh_token_expires_in']) ? now()->addSeconds((int) $token['refresh_token_expires_in']) : null,
        ])->save();
    }
}
