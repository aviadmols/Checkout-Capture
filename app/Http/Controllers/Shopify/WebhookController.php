<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Models\Activation;
use App\Models\Capture;
use App\Models\Order;
use App\Models\Shop;
use App\Models\TrackedCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One endpoint for every topic in shopify.app.toml; signature checked by VerifyShopifyWebhook.
 * Shopify retries anything that isn't 2xx, so handlers are idempotent and quick.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $topic = (string) $request->header('X-Shopify-Topic');
        $domain = Shop::normalizeHost((string) $request->header('X-Shopify-Shop-Domain'));
        $shop = Shop::query()->where('shop_domain', $domain)->first();
        $payload = $request->json()->all();

        match ($topic) {
            'app/uninstalled' => $shop && $this->uninstalled($shop),
            'orders/create' => $shop && $this->orderCreated($shop, $payload),
            'customers/data_request' => $this->dataRequested($domain, $payload),
            'customers/redact' => $shop && $this->redactCustomer($shop, $payload),
            'shop/redact' => $shop?->delete(),
            default => Log::info("Unhandled Shopify webhook topic {$topic} for {$domain}"),
        };

        return response()->json(['ok' => true]);
    }

    /** The token is dead once the app is removed; the widget switches itself off. */
    private function uninstalled(Shop $shop): bool
    {
        return $shop->update([
            'shopify_access_token' => null,
            'uninstalled_at' => now(),
            'mode' => Shop::MODE_OFF,
            'billing_status' => $shop->billing_status ? 'cancelled' : null,
        ]);
    }

    /**
     * "Orders received": an order counts when it carries the _capture_email cart attribute
     * the widget set, or when its email belongs to a shopper this shop captured.
     */
    private function orderCreated(Shop $shop, array $order): bool
    {
        $attributes = collect($order['note_attributes'] ?? [])->pluck('value', 'name');
        $attributeEmail = Str::lower(trim((string) $attributes->get('_capture_email')));
        $orderEmail = Str::lower(trim((string) ($order['email'] ?? $order['contact_email'] ?? '')));

        $email = $attributeEmail ?: $orderEmail;
        $capture = $email ? Capture::query()->where('shop_id', $shop->id)->where('email', $email)->first() : null;

        if (! $attributeEmail && ! $capture) {
            return false;
        }

        Order::query()->updateOrCreate(
            ['shop_id' => $shop->id, 'shopify_order_id' => (int) $order['id']],
            [
                'capture_id' => $capture?->id,
                'name' => $order['name'] ?? null,
                'email' => $email,
                'matched_by' => $attributeEmail ? 'attribute' : 'email',
                'total_cents' => (int) round(((float) ($order['total_price'] ?? 0)) * 100),
                'currency' => Str::upper((string) ($order['currency'] ?? 'USD')),
                'ordered_at' => isset($order['created_at']) ? Carbon::parse($order['created_at']) : now(),
            ],
        );

        return true;
    }

    /**
     * We hold captures (email, name, phone, birthday, consent) and carts for this customer.
     * Shopify expects the merchant to be given that data; it's logged for the operator to send.
     */
    private function dataRequested(string $domain, array $payload): bool
    {
        Log::warning('Shopify customers/data_request — send the customer\'s captured data to the merchant', [
            'shop' => $domain,
            'customer_email' => $payload['customer']['email'] ?? null,
            'data_request_id' => $payload['data_request']['id'] ?? null,
        ]);

        return true;
    }

    private function redactCustomer(Shop $shop, array $payload): bool
    {
        $email = Str::lower(trim((string) ($payload['customer']['email'] ?? '')));

        if ($email === '') {
            return false;
        }

        TrackedCart::query()->where('shop_id', $shop->id)->where('email', $email)->delete();
        Activation::query()->where('shop_id', $shop->id)->where('email', $email)->delete();
        Order::query()->where('shop_id', $shop->id)->where('email', $email)->delete();
        Capture::query()->where('shop_id', $shop->id)->where('email', $email)->delete();

        return true;
    }
}
