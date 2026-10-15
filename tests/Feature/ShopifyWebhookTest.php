<?php

namespace Tests\Feature;

use App\Models\Activation;
use App\Models\Capture;
use App\Models\Order;
use App\Models\Shop;
use App\Models\TrackedCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShopifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shopify.api_secret' => 'shpss_test_secret']);

        $this->shop = Shop::factory()->create([
            'shop_domain' => 'mills.myshopify.com',
            'shopify_access_token' => 'shpat_test',
            'installed_at' => now(),
        ]);
    }

    private function webhook(string $topic, array $payload, ?string $secret = 'shpss_test_secret')
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/shopify/webhooks', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'mills.myshopify.com',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, (string) $secret, true)),
        ], $body);
    }

    public function test_unsigned_or_wrongly_signed_webhooks_are_rejected(): void
    {
        $this->webhook('app/uninstalled', [], 'wrong-secret')->assertUnauthorized();
        $this->assertTrue($this->shop->fresh()->isInstalled());
    }

    public function test_uninstall_drops_the_token_and_switches_the_widget_off(): void
    {
        $this->webhook('app/uninstalled', ['id' => 1])->assertOk();

        $shop = $this->shop->fresh();
        $this->assertFalse($shop->isInstalled());
        $this->assertNull($shop->shopify_access_token);
        $this->assertSame(Shop::MODE_OFF, $shop->mode);
        $this->getJson("/api/v1/widget/{$shop->public_key}")->assertExactJson(['enabled' => false]);
    }

    public function test_orders_from_captured_shoppers_are_recorded_once(): void
    {
        $capture = Capture::factory()->for($this->shop)->create(['email' => 'dana@example.com']);

        $order = [
            'id' => 5550001, 'name' => '#1001', 'email' => 'Dana@Example.com', 'total_price' => '258.00', 'currency' => 'ils',
            'created_at' => '2026-10-15T10:00:00+03:00',
            'note_attributes' => [['name' => '_capture_email', 'value' => 'dana@example.com']],
        ];

        $this->webhook('orders/create', $order)->assertOk();
        $this->webhook('orders/create', $order)->assertOk(); // Shopify retries

        $recorded = Order::sole();
        $this->assertSame($capture->id, $recorded->capture_id);
        $this->assertSame('attribute', $recorded->matched_by);
        $this->assertSame(25800, $recorded->total_cents);
        $this->assertSame('ILS', $recorded->currency);

        // Known shopper without the attribute (e.g. ordered later from another device) → matched by email.
        $this->webhook('orders/create', [...$order, 'id' => 5550002, 'note_attributes' => []])->assertOk();
        $this->assertSame('email', Order::where('shopify_order_id', 5550002)->value('matched_by'));

        // Anyone else is not ours to count.
        $this->webhook('orders/create', [...$order, 'id' => 5550003, 'email' => 'stranger@example.com', 'note_attributes' => []])->assertOk();
        $this->assertSame(2, Order::count());
    }

    public function test_customer_redact_erases_everything_we_hold_for_that_email(): void
    {
        $capture = Capture::factory()->for($this->shop)->create(['email' => 'dana@example.com']);
        Capture::factory()->for($this->shop)->create(['email' => 'keep@example.com']);
        Activation::create(['shop_id' => $this->shop->id, 'capture_id' => $capture->id, 'email' => 'dana@example.com', 'source' => 'popup', 'period' => '2026-10', 'dedupe_key' => 'x']);
        TrackedCart::create(['shop_id' => $this->shop->id, 'email' => 'dana@example.com', 'source' => 'url', 'cart_token' => 't', 'last_activity_at' => now()]);

        $this->webhook('customers/redact', ['shop_domain' => 'mills.myshopify.com', 'customer' => ['id' => 1, 'email' => 'Dana@Example.com']])->assertOk();

        $this->assertSame(['keep@example.com'], Capture::pluck('email')->all());
        $this->assertSame(0, Activation::count());
        $this->assertSame(0, TrackedCart::count());
    }

    public function test_shop_redact_deletes_the_shop_and_data_request_is_acknowledged(): void
    {
        Capture::factory()->for($this->shop)->create();

        $this->webhook('customers/data_request', ['customer' => ['email' => 'dana@example.com'], 'data_request' => ['id' => 9]])->assertOk();
        $this->webhook('shop/redact', ['shop_domain' => 'mills.myshopify.com'])->assertOk();

        $this->assertSame(0, Shop::count());
        $this->assertSame(0, Capture::count());
    }

    public function test_billing_periods_follow_the_shopify_cycle_when_anchored(): void
    {
        $this->assertSame(now()->format('Y-m'), $this->shop->periodFor());

        $this->shop->update(['billing_cycle_anchor' => Carbon::parse('2026-09-20 12:00:00')]);

        $this->assertSame('2026-09-20', $this->shop->periodFor(Carbon::parse('2026-10-19 11:00:00')));
        $this->assertSame('2026-10-20', $this->shop->periodFor(Carbon::parse('2026-10-20 12:00:01')));
        $this->assertSame('2026-09-20', $this->shop->previousPeriod(Carbon::parse('2026-10-25')));
    }
}
