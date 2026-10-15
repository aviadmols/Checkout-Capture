<?php

namespace App\Services\Shopify;

use App\Models\TrackedCart;
use Illuminate\Support\Str;

/**
 * Fires the app's "Cart abandoned" Shopify Flow trigger, so the merchant's own Flow /
 * Shopify marketing automations act on it (e.g. Shopify Email's "Send marketing email").
 * The trigger carries a customer reference — the shopper is found by email, or created
 * as a Shopify customer, subscribed to email marketing only if they opted in with us.
 */
class FlowTrigger
{
    public const HANDLE = 'cart-abandoned';

    public function __construct(private ShopifyClient $shopify) {}

    public function cartAbandoned(TrackedCart $cart): void
    {
        $shop = $cart->shop;
        $customerId = $this->customerId($cart);

        $result = $this->shopify->graphql($shop, <<<'GQL'
            mutation($handle: String, $payload: JSON) {
              flowTriggerReceive(handle: $handle, payload: $payload) {
                userErrors { field message }
              }
            }
            GQL, [
            'handle' => self::HANDLE,
            // Keys match extensions/cart-abandoned-trigger/shopify.extension.toml.
            'payload' => [
                'customer_id' => $customerId,
                'Customer email' => $cart->email,
                'Recovery url' => (string) $cart->recoveryUrl(),
                'Cart total' => $cart->total_cents !== null ? number_format($cart->total_cents / 100, 2, '.', '') : '0.00',
                'Currency' => (string) $cart->currency,
                'Products' => Str::limit(collect($cart->items)->map(fn (array $i) => ($i['product_title'] ?? '').' × '.($i['quantity'] ?? 1))->implode(', '), 250, '…'),
                'Source' => $cart->source,
            ],
        ]);

        ShopifyClient::assertNoUserErrors($result['flowTriggerReceive'] ?? null, 'flowTriggerReceive');
    }

    /** Numeric customer id (legacyResourceId), as Flow's customer_reference expects. */
    private function customerId(TrackedCart $cart): int
    {
        $shop = $cart->shop;
        $email = str_replace('"', '', $cart->email);

        $found = $this->shopify->graphql($shop, <<<'GQL'
            query($query: String!) { customers(first: 1, query: $query) { nodes { legacyResourceId } } }
            GQL, ['query' => "email:\"{$email}\""]);

        if ($id = $found['customers']['nodes'][0]['legacyResourceId'] ?? null) {
            return (int) $id;
        }

        $capture = $cart->capture;
        $input = array_filter([
            'email' => $cart->email,
            'firstName' => $capture?->first_name,
            'lastName' => $capture?->last_name,
            'emailMarketingConsent' => $capture?->accepts_marketing
                ? ['marketingState' => 'SUBSCRIBED', 'marketingOptInLevel' => 'SINGLE_OPT_IN']
                : null,
        ]);

        $created = $this->shopify->graphql($shop, <<<'GQL'
            mutation($input: CustomerInput!) {
              customerCreate(input: $input) {
                customer { legacyResourceId }
                userErrors { field message }
              }
            }
            GQL, ['input' => $input]);

        ShopifyClient::assertNoUserErrors($created['customerCreate'] ?? null, 'customerCreate');

        return (int) $created['customerCreate']['customer']['legacyResourceId'];
    }
}
