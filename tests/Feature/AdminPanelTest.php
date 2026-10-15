<?php

namespace Tests\Feature;

use App\Filament\Resources\Shops\Pages\CreateShop;
use App\Filament\Resources\Shops\Pages\EditShop;
use App\Models\Capture;
use App\Models\Shop;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
            'source' => 'url',
            'cart_items' => [['variant_id' => 1, 'product_title' => 'Shake', 'variant_title' => null, 'quantity' => 1, 'price' => 9900]],
        ]);

        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk()->assertSee('New today');
        $this->get('/admin/shops')->assertOk()->assertSee('Mills IL')->assertSee('Popup');
        $this->get('/admin/shops/create')->assertOk()->assertSee('How it works')->assertSee('Email from link only');
        $this->get("/admin/shops/{$shop->id}/edit?tab=popup::data::tab")->assertOk()
            ->assertSee('Split with image')
            ->assertSee('SMS marketing consent');
        $this->get("/admin/shops/{$shop->id}/edit?tab=install::data::tab")->assertOk()
            ->assertSee($shop->public_key)
            ->assertSee('?email={{ email }}', false);
        $this->get("/admin/shops/{$shop->id}/edit?tab=usage::data::tab")->assertOk()->assertSee('checkouts created')->assertSee('Growth $14');
        $this->get('/admin/tracked-carts')->assertOk()->assertSee('Abandoned carts');
        $this->get('/admin/captures')->assertOk()->assertSee('dana@example.com')->assertSee('Link');
        $this->get("/admin/captures/{$capture->id}")->assertOk()->assertSee('Shake')->assertSee('Email from link');
    }

    public function test_creating_a_shop_saves_mode_fields_and_design(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateShop::class)
            ->fillForm([
                'name' => 'Bobo',
                'shop_domain' => 'bobo-il.myshopify.com',
                'mode' => Shop::MODE_POPUP,
                'settings.design' => 'split',
                'settings.image_url' => 'https://cdn.shopify.com/s/files/hero.jpg',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $shop = Shop::sole();
        $this->assertSame(Shop::MODE_POPUP, $shop->mode);
        $this->assertSame('split', $shop->resolvedSettings()['design']);
        $this->assertSame(
            ['email', 'full_name', 'accepts_marketing'],
            array_column($shop->resolvedSettings()['fields'], 'key'),
            'new shops start with the default field set',
        );
    }

    public function test_fill_in_hebrew_translates_every_text_and_label(): void
    {
        $this->actingAs(User::factory()->create());
        $shop = Shop::factory()->create();

        Livewire::test(EditShop::class, ['record' => $shop->getRouteKey()])
            ->callAction(TestAction::make('fillHebrew')->schemaComponent('language'))
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = $shop->refresh()->resolvedSettings();
        $this->assertSame('rtl', $settings['direction']);
        $this->assertSame(Shop::HEBREW_TEXTS['title'], $settings['title']);
        $this->assertSame(Shop::HEBREW_TEXTS['close_label'], $settings['close_label']);
        $this->assertSame('אימייל', $settings['fields'][0]['label']);

        Livewire::test(EditShop::class, ['record' => $shop->getRouteKey()])
            ->callAction(TestAction::make('fillEnglish')->schemaComponent('language'))
            ->call('save');

        $settings = $shop->refresh()->resolvedSettings();
        $this->assertSame('ltr', $settings['direction']);
        $this->assertSame('Before you check out', $settings['title']);
        $this->assertSame('Email', $settings['fields'][0]['label']);
    }

    public function test_switching_to_link_only_keeps_the_popup_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $shop = Shop::factory()->create([
            'settings' => [
                'design' => 'split',
                'title' => 'Wait!',
                'fields' => [['key' => 'email'], ['key' => 'phone', 'enabled' => true, 'required' => true]],
            ],
        ]);

        Livewire::test(EditShop::class, ['record' => $shop->getRouteKey()])
            // Defaults show for settings this shop never saved, so saving doesn't flip them.
            ->assertFormSet(['mode' => Shop::MODE_POPUP, 'settings.url_capture' => true, 'settings.accent_color' => '#000000'])
            ->fillForm(['mode' => Shop::MODE_URL, 'settings.url_param' => 'em'])
            ->call('save')
            ->assertHasNoFormErrors();

        $shop->refresh();
        $settings = $shop->resolvedSettings();
        $this->assertSame(Shop::MODE_URL, $shop->mode);
        $this->assertSame('em', $settings['url_param']);
        $this->assertSame('split', $settings['design']);
        $this->assertSame('Wait!', $settings['title']);
        $this->assertTrue(collect($settings['fields'])->firstWhere('key', 'phone')['required']);
    }
}
