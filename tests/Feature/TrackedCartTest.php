<?php

namespace Tests\Feature;

use App\Events\CartAbandoned;
use App\Models\Shop;
use App\Models\TrackedCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TrackedCartTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->urlOnly()->create([
            'shop_domain' => 'mills.myshopify.com',
            'allowed_origins' => ['mills.co.il'],
            'settings' => ['abandoned_carts' => true, 'abandon_after_minutes' => 20],
        ]);
    }

    private function track(array $cart = [], array $overrides = [])
    {
        return $this->withHeader('Origin', 'https://mills.co.il')->postJson('/api/v1/carts', [
            'key' => $this->shop->public_key,
            'email' => 'Link@Example.com',
            'source' => 'url',
            'cart' => [
                'token' => 'c1-abc?key=1',
                'total_price' => 25800,
                'currency' => 'ils',
                'item_count' => 2,
                'items' => [['variant_id' => 4410, 'product_title' => 'Shake', 'variant_title' => null, 'quantity' => 2, 'price' => 12900]],
                ...$cart,
            ],
            ...$overrides,
        ]);
    }

    public function test_nothing_is_tracked_unless_the_shop_turned_it_on(): void
    {
        $this->shop->update(['settings' => ['abandoned_carts' => false]]);

        $this->track()->assertOk()->assertJson(['tracked' => false]);
        $this->assertSame(0, TrackedCart::count());
    }

    public function test_idle_carts_become_abandoned_once_with_a_recovery_link(): void
    {
        Event::fake([CartAbandoned::class]);

        $this->track()->assertOk()->assertJson(['tracked' => true, 'status' => 'active']);

        $this->travel(19)->minutes();
        $this->artisan('carts:detect-abandoned')->assertSuccessful();
        $this->assertSame(TrackedCart::ACTIVE, TrackedCart::sole()->status);

        $this->travel(2)->minutes();
        $this->artisan('carts:detect-abandoned')->assertSuccessful();
        $this->artisan('carts:detect-abandoned')->assertSuccessful();

        $cart = TrackedCart::sole();
        $this->assertSame(TrackedCart::ABANDONED, $cart->status);
        $this->assertSame('link@example.com', $cart->email);
        $this->assertSame('ILS', $cart->currency);
        $this->assertSame(
            'https://mills.co.il/cart/4410:2?checkout%5Bemail%5D=link%40example.com',
            $cart->recoveryUrl(),
        );
        Event::assertDispatchedTimes(CartAbandoned::class, 1);
    }

    public function test_activity_keeps_the_cart_alive_and_revives_an_abandoned_one(): void
    {
        $this->track();
        $this->travel(15)->minutes();
        $this->track(['item_count' => 3]);
        $this->travel(10)->minutes();
        $this->artisan('carts:detect-abandoned');
        $this->assertSame(TrackedCart::ACTIVE, TrackedCart::sole()->status, 'last activity was 10 minutes ago');

        $this->travel(15)->minutes();
        $this->artisan('carts:detect-abandoned');
        $this->assertSame(TrackedCart::ABANDONED, TrackedCart::sole()->status);

        $this->track();
        $this->assertSame(TrackedCart::ACTIVE, TrackedCart::sole()->status);
        $this->assertNull(TrackedCart::sole()->abandoned_at);
    }

    public function test_heading_to_checkout_or_emptying_the_cart_stops_tracking(): void
    {
        $this->track();
        $this->withHeader('Origin', 'https://mills.co.il')->postJson('/api/v1/activations', [
            'key' => $this->shop->public_key,
            'email' => 'link@example.com',
            'source' => 'url',
            'cart_token' => 'c1-abc?key=1',
        ])->assertCreated();

        $this->travel(60)->minutes();
        $this->artisan('carts:detect-abandoned');
        $this->track(['item_count' => 1]);
        $this->assertSame(TrackedCart::CHECKOUT, TrackedCart::sole()->status, 'checkout is final');

        $this->track(['token' => 'c1-other', 'item_count' => 0, 'items' => []]);
        $this->assertSame(TrackedCart::EMPTIED, TrackedCart::where('cart_token', 'c1-other')->sole()->status);
    }

    public function test_it_rejects_other_origins_and_switched_off_shops(): void
    {
        $this->withHeader('Origin', 'https://evil.example')->postJson('/api/v1/carts', [
            'key' => $this->shop->public_key, 'email' => 'a@b.co', 'source' => 'url',
            'cart' => ['token' => 't', 'item_count' => 1],
        ])->assertForbidden();

        $this->shop->update(['mode' => Shop::MODE_OFF]);
        $this->track()->assertNotFound();
    }
}
