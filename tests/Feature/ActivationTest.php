<?php

namespace Tests\Feature;

use App\Models\Activation;
use App\Models\Capture;
use App\Models\Shop;
use App\Support\UsagePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActivationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create([
            'shop_domain' => 'mills.myshopify.com',
            'allowed_origins' => ['mills.co.il'],
        ]);
    }

    private function activate(array $overrides = [], string $origin = 'https://mills.co.il')
    {
        return $this->withHeader('Origin', $origin)->postJson('/api/v1/activations', [
            'key' => $this->shop->public_key,
            'email' => 'Dana@Example.com',
            'source' => 'popup',
            'cart_token' => 'c1-abc?key=1',
            ...$overrides,
        ]);
    }

    public function test_it_counts_one_checkout_per_cart_per_month(): void
    {
        $capture = Capture::factory()->for($this->shop)->create(['email' => 'dana@example.com']);

        $this->activate()->assertCreated()->assertJson(['counted' => true]);
        $this->activate()->assertOk()->assertJson(['counted' => false]);
        $this->activate(['cart_token' => 'c1-other'])->assertCreated();

        $this->assertSame(2, $this->shop->activationCount());
        $this->assertSame($capture->id, Activation::first()->capture_id);
        $this->assertSame('dana@example.com', Activation::first()->email);

        Carbon::setTestNow(now()->addMonthNoOverflow());
        $this->activate()->assertCreated();
        $this->assertSame(1, $this->shop->activationCount());
    }

    public function test_without_a_cart_token_it_counts_once_per_email(): void
    {
        $this->activate(['cart_token' => null])->assertCreated();
        $this->activate(['cart_token' => null, 'email' => 'dana@example.com'])->assertOk();
        $this->activate(['cart_token' => null, 'email' => 'other@example.com', 'source' => 'url'])->assertCreated();

        $this->assertSame(2, $this->shop->activationCount());
    }

    public function test_it_rejects_switched_off_shops_foreign_origins_and_bad_input(): void
    {
        $this->activate([], 'https://evil.example')->assertForbidden();
        $this->activate(['source' => 'customer'])->assertUnprocessable();
        $this->activate(['email' => 'nope'])->assertUnprocessable();

        $this->shop->update(['mode' => Shop::MODE_OFF]);
        $this->activate()->assertNotFound();

        $this->assertSame(0, Activation::count());
    }

    public function test_activations_are_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->activate(['cart_token' => "cart-{$i}"])->assertSuccessful();
        }

        $this->activate(['cart_token' => 'cart-11'])->assertTooManyRequests();
    }

    public function test_plan_follows_monthly_usage(): void
    {
        $this->assertSame(['Free', 0], [UsagePlan::forActivations(0)['name'], UsagePlan::forActivations(0)['price']]);
        $this->assertSame(0, UsagePlan::forActivations(50)['price']);
        $this->assertSame(14, UsagePlan::forActivations(51)['price']);
        $this->assertSame(14, UsagePlan::forActivations(1000)['price']);
        $this->assertSame(25, UsagePlan::forActivations(1001)['price']);
        $this->assertSame('Growth · $14', UsagePlan::label(51));
    }
}
