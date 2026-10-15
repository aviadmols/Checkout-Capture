<?php

namespace Tests\Feature;

use App\Models\Capture;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_pages_render(): void
    {
        $shop = Shop::factory()->create(['name' => 'Mills IL']);
        $capture = Capture::factory()->for($shop)->create([
            'email' => 'dana@example.com',
            'cart_items' => [['variant_id' => 1, 'product_title' => 'Shake', 'variant_title' => null, 'quantity' => 1, 'price' => 9900]],
        ]);

        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSee('New today');
        $this->get('/admin/shops')->assertOk()->assertSee('Mills IL');
        $this->get('/admin/shops/create')->assertOk();
        $this->get("/admin/shops/{$shop->id}/edit?tab=install::data::tab")->assertOk()->assertSee($shop->public_key);
        $this->get('/admin/captures')->assertOk()->assertSee('dana@example.com');
        $this->get("/admin/captures/{$capture->id}")->assertOk()->assertSee('Shake');
    }
}
