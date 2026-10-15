<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_admin_login(): void
    {
        $shop = Shop::factory()->create();

        $this->get(route('shops.preview', $shop))->assertRedirect('/admin/login');
    }

    public function test_it_renders_the_popup_with_inline_config_even_when_the_shop_is_off(): void
    {
        $shop = Shop::factory()->off()->create(['settings' => ['title' => 'Before you pay', 'design' => 'classic']]);

        $this->actingAs(User::factory()->create())
            ->get(route('shops.preview', $shop).'?design=split')
            ->assertOk()
            ->assertSee('data-preview="true"', false)
            ->assertSee('id="checkout-capture-config"', false)
            ->assertSee('Before you pay')
            ->assertSee('"design":"split"', false)
            ->assertSee('"mode":"popup"', false);
    }
}
