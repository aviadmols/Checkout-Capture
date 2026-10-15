<?php

namespace Tests\Feature;

use App\Filament\Resources\Shops\Pages\EditShop;
use App\Models\Capture;
use App\Models\Shop;
use App\Models\TrackedCart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AbandonedCartWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'https://hooks.example.com/abandoned';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create([
            'name' => 'Mills IL',
            'shop_domain' => 'mills.myshopify.com',
            'allowed_origins' => ['mills.co.il'],
            'webhook_url' => self::HOOK,
            'settings' => ['abandoned_carts' => true, 'abandon_after_minutes' => 10],
        ]);
    }

    private function idleCart(): TrackedCart
    {
        Capture::factory()->for($this->shop)->create([
            'email' => 'dana@example.com', 'first_name' => 'Dana', 'last_name' => 'Cohen', 'accepts_marketing' => true,
        ]);

        $this->withHeader('Origin', 'https://mills.co.il')->postJson('/api/v1/carts', [
            'key' => $this->shop->public_key,
            'email' => 'dana@example.com',
            'source' => 'url',
            'cart' => [
                'token' => 'c1-abc', 'total_price' => 25800, 'currency' => 'ILS', 'item_count' => 2,
                'items' => [['variant_id' => 4410, 'product_title' => 'Shake', 'variant_title' => 'Chocolate', 'quantity' => 2, 'price' => 12900]],
            ],
        ])->assertOk();

        $this->travel(11)->minutes();

        return TrackedCart::sole();
    }

    public function test_an_abandoned_cart_is_posted_once_signed_with_the_shop_secret(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $this->idleCart();

        $this->artisan('carts:detect-abandoned')->assertSuccessful();
        $this->artisan('carts:detect-abandoned')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $payload = json_decode($request->body(), true);
            $signature = 'sha256='.hash_hmac('sha256', $request->body(), $this->shop->fresh()->webhook_secret);

            return $request->url() === self::HOOK
                && $request->header('X-Checkout-Capture-Event')[0] === 'cart.abandoned'
                && hash_equals($signature, $request->header('X-Checkout-Capture-Signature')[0])
                && $payload['customer'] === [
                    'email' => 'dana@example.com', 'first_name' => 'Dana', 'last_name' => 'Cohen',
                    'phone' => null, 'accepts_marketing' => true, 'accepts_sms' => false,
                ]
                && $payload['source'] === 'url'
                && $payload['cart']['total_price'] === 258
                && $payload['cart']['items'][0] === ['variant_id' => 4410, 'product_title' => 'Shake', 'variant_title' => 'Chocolate', 'quantity' => 2, 'price' => 129]
                && $payload['recovery_url'] === 'https://mills.co.il/cart/4410:2?checkout%5Bemail%5D=dana%40example.com';
        });

        $cart = TrackedCart::sole();
        $this->assertSame('sent', $cart->webhook_status);
        $this->assertSame(200, $cart->webhook_response_code);
    }

    public function test_a_failing_endpoint_is_recorded_and_can_be_resent(): void
    {
        Http::fake([self::HOOK => Http::sequence()
            ->push('down', 500)->push('down', 500)->push('down', 500)
            ->push(['ok' => true], 200)]);
        $this->idleCart();

        $this->artisan('carts:detect-abandoned');

        $cart = TrackedCart::sole();
        $this->assertSame(TrackedCart::ABANDONED, $cart->status, 'a webhook failure never blocks abandonment');
        $this->assertSame('failed', $cart->webhook_status);
        $this->assertSame(500, $cart->webhook_response_code);

        $this->assertTrue(app(\App\Services\AbandonedCartWebhook::class)->deliver($cart));
        $this->assertSame('sent', $cart->fresh()->webhook_status);
    }

    public function test_no_webhook_url_means_nothing_is_sent(): void
    {
        Http::fake();
        $this->shop->update(['webhook_url' => null]);
        $this->idleCart();

        $this->artisan('carts:detect-abandoned');

        Http::assertNothingSent();
        $this->assertSame(TrackedCart::ABANDONED, TrackedCart::sole()->status);
        $this->assertNull(TrackedCart::sole()->webhook_status);
    }

    public function test_secret_is_generated_and_webhook_details_never_reach_the_storefront(): void
    {
        $this->assertSame(40, strlen($this->shop->webhook_secret));

        $config = $this->getJson("/api/v1/widget/{$this->shop->public_key}")->assertOk()->json();
        $this->assertStringNotContainsString(self::HOOK, json_encode($config));
        $this->assertStringNotContainsString($this->shop->webhook_secret, json_encode($config));
    }

    public function test_admin_can_send_a_test_webhook(): void
    {
        Http::fake([self::HOOK => Http::response(['ok' => true])]);
        $this->actingAs(User::factory()->create());

        Livewire::test(EditShop::class, ['record' => $this->shop->getRouteKey()])
            ->callAction('testWebhook')
            ->assertNotified('Test webhook delivered');

        Http::assertSent(fn (Request $request) => $request->header('X-Checkout-Capture-Event')[0] === 'test'
            && json_decode($request->body(), true)['customer']['email'] === 'test.shopper@example.com');
    }
}
