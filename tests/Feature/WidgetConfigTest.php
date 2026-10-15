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
            'settings' => [
                'title' => 'Hold on',
                'design' => 'split',
                'subtitle' => null,
                'unknown' => 'dropped',
                'fields' => [
                    ['key' => 'phone', 'enabled' => true, 'required' => true, 'label' => 'Mobile'],
                    ['key' => 'email', 'enabled' => false, 'required' => false],
                    ['key' => 'full_name', 'enabled' => false],
                    ['key' => 'accepts_sms', 'enabled' => true, 'required' => true],
                    ['key' => 'nonsense', 'enabled' => true],
                ],
            ],
        ]);

        $response = $this->getJson("/api/v1/widget/{$shop->public_key}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('mode', Shop::MODE_POPUP)
            ->assertJsonPath('shopDomain', $shop->shop_domain)
            ->assertJsonPath('storefrontToken', 'sf_public_token')
            ->assertJsonPath('settings.title', 'Hold on')
            ->assertJsonPath('settings.design', 'split')
            ->assertJsonPath('settings.subtitle', Shop::DEFAULT_SETTINGS['subtitle'])
            ->assertJsonMissingPath('settings.unknown');

        // Saved order kept, email forced on + required, consent never required,
        // disabled fields dropped, fields missing from the saved list fall back to defaults.
        $this->assertSame([
            ['key' => 'phone', 'type' => 'tel', 'label' => 'Mobile', 'enabled' => true, 'required' => true],
            ['key' => 'email', 'type' => 'email', 'label' => 'Email', 'enabled' => true, 'required' => true],
            ['key' => 'accepts_sms', 'type' => 'checkbox', 'label' => Shop::FIELDS['accepts_sms']['label'], 'enabled' => true, 'required' => false],
            ['key' => 'accepts_marketing', 'type' => 'checkbox', 'label' => Shop::FIELDS['accepts_marketing']['label'], 'enabled' => true, 'required' => false],
        ], $response->json('settings.fields'));

        $this->assertArrayNotHasKey('public_key', $response->json());
    }

    public function test_url_mode_always_reads_the_email_from_the_link(): void
    {
        $shop = Shop::factory()->urlOnly()->create(['settings' => ['url_capture' => false, 'url_param' => 'e']]);

        $this->getJson("/api/v1/widget/{$shop->public_key}")
            ->assertJsonPath('mode', Shop::MODE_URL)
            ->assertJsonPath('settings.url_capture', true)
            ->assertJsonPath('settings.url_param', 'e');
    }

    public function test_unknown_design_falls_back_to_classic(): void
    {
        $shop = Shop::factory()->create(['settings' => ['design' => 'neon']]);

        $this->getJson("/api/v1/widget/{$shop->public_key}")->assertJsonPath('settings.design', 'classic');
    }

    public function test_unknown_and_switched_off_shops_are_disabled(): void
    {
        $this->getJson('/api/v1/widget/cc_missing')->assertOk()->assertExactJson(['enabled' => false]);

        $shop = Shop::factory()->off()->create();
        $this->getJson("/api/v1/widget/{$shop->public_key}")->assertOk()->assertExactJson(['enabled' => false]);
    }

    public function test_widget_script_is_served(): void
    {
        $this->assertFileExists(public_path('js/checkout-capture.js'));
    }
}
