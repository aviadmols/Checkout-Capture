<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_key_is_generated_and_domain_normalized(): void
    {
        $shop = Shop::factory()->create(['shop_domain' => 'https://Mills.MyShopify.com/']);

        $this->assertMatchesRegularExpression('/^cc_[a-z0-9]{32}$/', $shop->public_key);
        $this->assertSame('mills.myshopify.com', $shop->shop_domain);
    }

    public function test_allows_origin(): void
    {
        $shop = Shop::factory()->make([
            'shop_domain' => 'mills.myshopify.com',
            'allowed_origins' => ['https://www.Mills.co.il/', '*.shopifypreview.com'],
        ]);

        $this->assertTrue($shop->allowsOrigin('https://www.mills.co.il'));
        $this->assertTrue($shop->allowsOrigin('https://mills.myshopify.com'));
        $this->assertTrue($shop->allowsOrigin('https://x1.shopifypreview.com'));
        $this->assertFalse($shop->allowsOrigin('https://mills.co.il'));
        $this->assertFalse($shop->allowsOrigin('https://www.mills.co.il.evil.com'));
        $this->assertFalse($shop->allowsOrigin(null));

        $open = Shop::factory()->make(['allowed_origins' => []]);
        $this->assertTrue($open->allowsOrigin(null));
        $this->assertTrue($open->allowsOrigin('https://anything.example'));
    }
}
