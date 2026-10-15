<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_public_config_merged_with_defaults(): void
    {
        $shop = Shop::factory()->create([
            'storefront_access_token' => 'sf_public_token',
            'settings' => ['title' => 'Hold on', 'phone_field' => 'required', 'subtitle' => null, 'unknown' => 'dropped'],
        ]);

        $response = $this->getJson("/api/v1/widget/{$shop->public_key}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('shopDomain', $shop->shop_domain)
            ->assertJsonPath('storefrontToken', 'sf_public_token')
            ->assertJsonPath('settings.title', 'Hold on')
            ->assertJsonPath('settings.phone_field', 'required')
            ->assertJsonPath('settings.subtitle', Shop::DEFAULT_SETTINGS['subtitle'])
            ->assertJsonMissingPath('settings.unknown');

        $this->assertArrayNotHasKey('public_key', $response->json());
    }

    public function test_unknown_and_inactive_shops_are_disabled(): void
    {
        $this->getJson('/api/v1/widget/cc_missing')->assertOk()->assertExactJson(['enabled' => false]);

        $shop = Shop::factory()->inactive()->create();
        $this->getJson("/api/v1/widget/{$shop->public_key}")->assertOk()->assertExactJson(['enabled' => false]);
    }

    public function test_widget_script_is_served(): void
    {
        $this->assertFileExists(public_path('js/checkout-capture.js'));
    }
}
