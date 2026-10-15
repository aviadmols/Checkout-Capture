<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Admin GraphQL API calls with the shop's offline token, refreshed before it expires. */
class ShopifyClient
{
    public function __construct(private TokenExchange $tokens) {}

    /**
     * @return array<string, mixed> the `data` object
     *
     * @throws RuntimeException on transport, GraphQL or missing-token errors
     */
    public function graphql(Shop $shop, string $query, array $variables = []): array
    {
        if ($this->tokens->needsRefresh($shop) && $shop->shopify_refresh_token) {
            $this->tokens->refresh($shop);
            $shop->refresh();
        }

        if (! $shop->shopify_access_token) {
            throw new RuntimeException("Shop {$shop->shop_domain} has no Admin API token.");
        }

        $response = Http::timeout(15)
            ->withHeaders(['X-Shopify-Access-Token' => $shop->shopify_access_token])
            ->post($this->endpoint($shop), ['query' => $query, 'variables' => (object) $variables]);

        if ($response->failed()) {
            throw new RuntimeException("Shopify API HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }

        if ($errors = $response->json('errors')) {
            throw new RuntimeException('Shopify API: '.collect($errors)->pluck('message')->implode('; '));
        }

        return $response->json('data') ?? [];
    }

    /** Throws when a mutation payload reports userErrors. */
    public static function assertNoUserErrors(?array $payload, string $mutation): void
    {
        $errors = $payload['userErrors'] ?? [];

        if ($payload === null || $errors !== []) {
            throw new RuntimeException("{$mutation}: ".(collect($errors)->pluck('message')->implode('; ') ?: 'no payload'));
        }
    }

    private function endpoint(Shop $shop): string
    {
        return "https://{$shop->shop_domain}/admin/api/".config('shopify.api_version').'/graphql.json';
    }
}
