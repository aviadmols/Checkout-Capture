<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Str;

/**
 * Runs after every token exchange (first install, reinstall, expired refresh token).
 * Everything here is idempotent.
 */
class ShopInstaller
{
    public function __construct(private ShopifyClient $shopify, private Billing $billing) {}

    public function install(Shop $shop): void
    {
        $data = $this->shopify->graphql($shop, <<<'GQL'
            query {
              shop { name primaryDomain { host } }
              currentAppInstallation { id }
            }
            GQL);

        $host = Shop::normalizeHost($data['shop']['primaryDomain']['host'] ?? $shop->shop_domain);
        $twin = Str::startsWith($host, 'www.') ? Str::after($host, 'www.') : "www.{$host}";
        $reinstall = $shop->uninstalled_at !== null;

        $shop->fill([
            'name' => $data['shop']['name'] ?? $shop->name,
            'allowed_origins' => $shop->allowed_origins ?: array_values(array_unique([$host, $twin])),
            'installed_at' => $reinstall || ! $shop->installed_at ? now() : $shop->installed_at,
            'uninstalled_at' => null,
            'mode' => $reinstall && $shop->mode === Shop::MODE_OFF ? Shop::MODE_POPUP : $shop->mode,
        ])->save();

        $this->ensureStorefrontToken($shop);
        $this->publishEmbedConfig($shop, $data['currentAppInstallation']['id']);
        $this->billing->sync($shop);
    }

    /** The cart's buyer identity is set through the Storefront API — the app mints its own token. */
    private function ensureStorefrontToken(Shop $shop): void
    {
        if ($shop->storefront_access_token) {
            return;
        }

        $result = $this->shopify->graphql($shop, <<<'GQL'
            mutation($input: StorefrontAccessTokenInput!) {
              storefrontAccessTokenCreate(input: $input) {
                storefrontAccessToken { accessToken }
                userErrors { field message }
              }
            }
            GQL, ['input' => ['title' => 'Checkout Capture']]);

        ShopifyClient::assertNoUserErrors($result['storefrontAccessTokenCreate'] ?? null, 'storefrontAccessTokenCreate');

        $shop->update(['storefront_access_token' => $result['storefrontAccessTokenCreate']['storefrontAccessToken']['accessToken']]);
    }

    /**
     * The theme app embed reads `app.metafields.checkout_capture.config` to know which
     * shop it is and where the API lives — no keys pasted into themes.
     */
    private function publishEmbedConfig(Shop $shop, string $installationId): void
    {
        $result = $this->shopify->graphql($shop, <<<'GQL'
            mutation($metafields: [MetafieldsSetInput!]!) {
              metafieldsSet(metafields: $metafields) {
                metafields { id }
                userErrors { field message }
              }
            }
            GQL, ['metafields' => [[
                'namespace' => config('shopify.metafield_namespace'),
                'key' => 'config',
                'type' => 'json',
                'ownerId' => $installationId,
                'value' => json_encode([
                    'key' => $shop->public_key,
                    'api' => rtrim(config('app.url'), '/'),
                ]),
            ]]]);

        ShopifyClient::assertNoUserErrors($result['metafieldsSet'] ?? null, 'metafieldsSet');
    }
}
