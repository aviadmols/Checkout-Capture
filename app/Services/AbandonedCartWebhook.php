<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\TrackedCart;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Hands an abandoned cart to the shop's own tools (n8n, Make, Zapier, an ESP…) — we
 * never send the recovery message ourselves. Each request is signed:
 * X-Checkout-Capture-Signature: sha256=HMAC_SHA256(raw body, shop webhook secret).
 */
class AbandonedCartWebhook
{
    public const EVENT_ABANDONED = 'cart.abandoned';

    public const EVENT_TEST = 'test';

    public function deliver(TrackedCart $cart): bool
    {
        $shop = $cart->shop;

        if (! $shop?->webhook_url) {
            return false;
        }

        $result = $this->post($shop, $this->payload($cart));

        $cart->forceFill([
            'webhook_status' => $result['ok'] ? 'sent' : 'failed',
            'webhook_attempted_at' => now(),
            'webhook_response_code' => $result['status'],
            'webhook_error' => $result['error'],
        ])->save();

        return $result['ok'];
    }

    /** @return array{ok: bool, status: ?int, error: ?string} */
    public function sendTest(Shop $shop): array
    {
        $sample = new TrackedCart([
            'email' => 'test.shopper@example.com',
            'source' => 'url',
            'cart_token' => 'test-cart-token',
            'status' => TrackedCart::ABANDONED,
            'currency' => 'ILS',
            'total_cents' => 25800,
            'item_count' => 2,
            'items' => [['variant_id' => 1, 'product_title' => 'Sample product', 'variant_title' => null, 'quantity' => 2, 'price' => 12900]],
            'last_activity_at' => now()->subMinutes(30),
            'abandoned_at' => now(),
        ]);
        $sample->setRelation('shop', $shop);
        $sample->setRelation('capture', null);

        return $this->post($shop, ['event' => self::EVENT_TEST] + $this->payload($sample));
    }

    public function payload(TrackedCart $cart): array
    {
        $shop = $cart->shop;
        $capture = $cart->capture;

        return [
            'event' => self::EVENT_ABANDONED,
            'id' => $cart->id,
            'shop' => [
                'name' => $shop->name,
                'myshopify_domain' => $shop->shop_domain,
                'storefront_domain' => $shop->storefrontHost(),
            ],
            'customer' => [
                'email' => $cart->email,
                'first_name' => $capture?->first_name,
                'last_name' => $capture?->last_name,
                'phone' => $capture?->phone,
                'accepts_marketing' => (bool) $capture?->accepts_marketing,
                'accepts_sms' => (bool) $capture?->accepts_sms,
            ],
            // How we recognised the shopper: url (email in a link), popup, form (store form) or customer (logged in).
            'source' => $cart->source,
            'cart' => [
                'token' => $cart->cart_token,
                'currency' => $cart->currency,
                'total_price' => $cart->total_cents !== null ? round($cart->total_cents / 100, 2) : null,
                'total_price_cents' => $cart->total_cents,
                'item_count' => $cart->item_count,
                'items' => array_map(fn (array $item) => [
                    'variant_id' => $item['variant_id'] ?? null,
                    'product_title' => $item['product_title'] ?? null,
                    'variant_title' => $item['variant_title'] ?? null,
                    'quantity' => $item['quantity'] ?? null,
                    'price' => isset($item['price']) ? round($item['price'] / 100, 2) : null,
                ], $cart->items ?? []),
            ],
            // Rebuilds the cart on any device and opens checkout with the email filled in.
            'recovery_url' => $cart->recoveryUrl(),
            'last_activity_at' => $cart->last_activity_at?->toIso8601String(),
            'abandoned_at' => $cart->abandoned_at?->toIso8601String(),
        ];
    }

    /** @return array{ok: bool, status: ?int, error: ?string} */
    private function post(Shop $shop, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            /** @var Response $response */
            $response = Http::timeout(10)
                ->retry(3, 2000, throw: false)
                ->withHeaders([
                    'X-Checkout-Capture-Event' => $payload['event'],
                    'X-Checkout-Capture-Signature' => 'sha256='.hash_hmac('sha256', $body, (string) $shop->webhook_secret),
                    'User-Agent' => 'CheckoutCapture-Webhook/1.0',
                ])
                ->withBody($body, 'application/json')
                ->post($shop->webhook_url);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'status' => null, 'error' => Str::limit($e->getMessage(), 500)];
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'error' => $response->successful() ? null : Str::limit((string) $response->body(), 500),
        ];
    }
}
