<?php

namespace Tests\Feature;

use App\Console\Commands\SyncShopifyExtension;
use App\Models\Activation;
use App\Models\Capture;
use App\Models\Shop;
use App\Models\TrackedCart;
use App\Models\UsageCharge;
use App\Services\Shopify\Billing;
use App\Services\Shopify\SessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ShopifyAppTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'mills.myshopify.com';

    /** @var list<string> GraphQL operations Shopify received, in order */
    private array $calls = [];

    /** Per-operation responses that replace the defaults below (can change mid-test). */
    private array $overrides = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shopify.api_key' => 'test-client-id',
            'shopify.api_secret' => 'test-client-secret',
        ]);
    }

    /** Fake Shopify: token endpoint + an Admin GraphQL API that answers by operation. */
    private function fakeShopify(): void
    {
        $this->calls = [];

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
                $this->calls[] = 'token:'.$request['grant_type'];

                return Http::response([
                    'access_token' => 'shpat_'.count($this->calls), 'scope' => 'read_orders,read_customers',
                    'expires_in' => 3600, 'refresh_token' => 'shprt_x', 'refresh_token_expires_in' => 7776000,
                ]);
            }

            $query = (string) (self::gql($request)['query'] ?? '');
            $operation = collect(['partnerDevelopment', 'primaryDomain', 'storefrontAccessTokenCreate', 'metafieldsSet', 'activeSubscriptions', 'appSubscriptionCreate', 'appUsageRecordCreate', 'customers(', 'customerCreate', 'flowTriggerReceive'])
                ->first(fn ($op) => str_contains($query, $op)) ?? 'unknown';
            $this->calls[] = $operation;

            return Http::response(['data' => $this->overrides[$operation] ?? match ($operation) {
                'primaryDomain' => ['shop' => ['name' => 'Mills IL', 'primaryDomain' => ['host' => 'www.mills.co.il']], 'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/1']],
                'storefrontAccessTokenCreate' => ['storefrontAccessTokenCreate' => ['storefrontAccessToken' => ['accessToken' => 'sf_minted'], 'userErrors' => []]],
                'metafieldsSet' => ['metafieldsSet' => ['metafields' => [['id' => '1']], 'userErrors' => []]],
                'activeSubscriptions' => ['currentAppInstallation' => ['activeSubscriptions' => []]],
                'appSubscriptionCreate' => ['appSubscriptionCreate' => ['confirmationUrl' => 'https://mills.myshopify.com/admin/charges/confirm', 'appSubscription' => ['id' => 'gid://shopify/AppSubscription/7'], 'userErrors' => []]],
                'appUsageRecordCreate' => ['appUsageRecordCreate' => ['appUsageRecord' => ['id' => 'gid://shopify/AppUsageRecord/'.count($this->calls)], 'userErrors' => []]],
                'customers(' => ['customers' => ['nodes' => [['legacyResourceId' => '987']]]],
                'customerCreate' => ['customerCreate' => ['customer' => ['legacyResourceId' => '988'], 'userErrors' => []]],
                'flowTriggerReceive' => ['flowTriggerReceive' => ['userErrors' => []]],
                default => [],
            }]);
        });
    }

    private function installedShop(array $attributes = []): Shop
    {
        return Shop::factory()->create([
            'shop_domain' => self::SHOP,
            'allowed_origins' => ['www.mills.co.il'],
            'shopify_access_token' => 'shpat_live',
            'shopify_token_expires_at' => now()->addHour(),
            'shopify_refresh_token' => 'shprt_live',
            'shopify_refresh_expires_at' => now()->addDays(90),
            'installed_at' => now(),
            ...$attributes,
        ]);
    }

    private function app(string $method, string $uri, array $data = [], ?string $token = null)
    {
        return $this->withHeader('Authorization', 'Bearer '.($token ?? SessionToken::issue(self::SHOP)))
            ->json($method, "/api/shopify/app/{$uri}", $data);
    }

    public function test_session_tokens_are_verified(): void
    {
        $this->assertSame(self::SHOP, SessionToken::verify(SessionToken::issue(self::SHOP))['shop']);

        $expired = SessionToken::issue(self::SHOP, -60);
        $this->expectException(RuntimeException::class);
        SessionToken::verify($expired);
    }

    public function test_bad_session_tokens_get_401_with_the_app_bridge_retry_header(): void
    {
        $this->app('GET', 'state', token: 'not.a.jwt')
            ->assertUnauthorized()
            ->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');

        config(['shopify.api_key' => 'another-app']);
        $token = SessionToken::issue(self::SHOP);
        config(['shopify.api_key' => 'test-client-id']);
        $this->app('GET', 'state', token: $token)->assertUnauthorized();

        $this->assertSame(0, Shop::count());
    }

    public function test_the_first_app_request_installs_the_shop(): void
    {
        $this->fakeShopify();

        $this->app('GET', 'state')
            ->assertOk()
            ->assertJsonPath('shop.name', 'Mills IL')
            ->assertJsonPath('settings.mode', Shop::MODE_POPUP)
            ->assertJsonPath('billing.active', false)
            ->assertJsonPath('billing.serving', true)
            ->assertJsonPath('setup.embed_live', false)
            ->assertJsonPath('setup.embed_url', 'https://mills.myshopify.com/admin/themes/current/editor?context=apps&activateAppId=test-client-id/app-embed');

        $shop = Shop::sole();
        $this->assertTrue($shop->isInstalled());
        $this->assertSame('sf_minted', $shop->storefront_access_token);
        $this->assertSame(['www.mills.co.il', 'mills.co.il'], $shop->allowed_origins);
        $this->assertSame(['token:urn:ietf:params:oauth:grant-type:token-exchange', 'primaryDomain', 'storefrontAccessTokenCreate', 'metafieldsSet', 'activeSubscriptions'], $this->calls);

        Http::assertSent(fn (Request $r) => str_contains(self::gql($r)['query'] ?? '', 'metafieldsSet')
            && json_decode(self::gql($r)['variables']['metafields'][0]['value'], true) === ['key' => $shop->public_key, 'api' => rtrim(config('app.url'), '/')]);

        // Already installed: later requests don't touch Shopify.
        $this->calls = [];
        $this->app('GET', 'state')->assertOk();
        $this->assertSame([], $this->calls);
    }

    public function test_an_expired_access_token_is_refreshed_before_calling_shopify(): void
    {
        $this->fakeShopify();
        $shop = $this->installedShop(['shopify_token_expires_at' => now()->subMinute()]);

        app(Billing::class)->sync($shop);

        $this->assertSame(['token:refresh_token', 'activeSubscriptions'], $this->calls);
        $this->assertTrue($shop->fresh()->shopify_token_expires_at->isFuture());
    }

    public function test_merchants_save_settings_from_the_embedded_app(): void
    {
        $this->fakeShopify();
        $shop = $this->installedShop(['settings' => ['title' => 'Old title']]);

        $state = $this->app('GET', 'state')->json();
        $fields = collect($state['settings']['fields'])->map(fn ($f) => [...$f, 'enabled' => in_array($f['key'], ['email', 'phone'])])->all();
        $phoneFirst = collect($fields)->sortByDesc(fn ($f) => $f['key'] === 'phone')->values()->all();

        $payload = [
            'mode' => Shop::MODE_URL,
            'webhook_url' => 'https://hooks.example.com/cc',
            'settings' => [
                'design' => 'split', 'direction' => 'rtl', 'title' => 'רגע לפני', 'subtitle' => '',
                'abandon_after_minutes' => 15, 'url_param' => 'em', 'accent_color' => '#ff0000',
                'allow_skip' => false, 'url_capture' => true, 'form_capture' => true, 'abandoned_carts' => true, 'identify_marketing_apps' => false,
                'fields' => $phoneFirst,
            ],
        ];

        $this->app('PUT', 'settings', $payload)
            ->assertOk()
            ->assertJsonPath('settings.mode', Shop::MODE_URL)
            ->assertJsonPath('settings.values.title', 'רגע לפני');

        $settings = $shop->fresh()->resolvedSettings();
        $this->assertSame('split', $settings['design']);
        $this->assertSame(15, $settings['abandon_after_minutes']);
        $this->assertSame(Shop::DEFAULT_SETTINGS['subtitle'], $settings['subtitle'], 'empty text falls back to the default');
        $this->assertSame(['phone', 'email'], array_column($settings['fields'], 'key'));
        $this->assertSame('https://hooks.example.com/cc', $shop->fresh()->webhook_url);

        $this->app('PUT', 'settings', [...$payload, 'webhook_url' => 'http://insecure.example'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('webhook_url');
    }

    public function test_free_tier_then_plan_approval_then_usage_charges_once_per_tier(): void
    {
        $this->fakeShopify();
        $shop = $this->installedShop();
        $billing = app(Billing::class);

        // 50 free checkouts, then the widget steps aside until the plan is approved.
        $this->activations($shop, 50);
        $this->assertFalse($billing->canServe($shop));
        $this->getJson("/api/v1/widget/{$shop->public_key}")->assertExactJson(['enabled' => false]);

        $this->app('POST', 'billing/subscribe')->assertOk()->assertJsonPath('confirmation_url', 'https://mills.myshopify.com/admin/charges/confirm');
        Http::assertSent(fn (Request $r) => str_contains(self::gql($r)['query'] ?? '', 'appSubscriptionCreate')
            && self::gql($r)['variables']['lineItems'][0]['plan']['appUsagePricingDetails']['cappedAmount'] === ['amount' => 25, 'currencyCode' => 'USD']
            && self::gql($r)['variables']['test'] === true);

        // Merchant approved → Shopify sends them back; we read the active subscription.
        $this->overrides = ['activeSubscriptions' => ['currentAppInstallation' => ['activeSubscriptions' => [[
            'id' => 'gid://shopify/AppSubscription/7', 'name' => Billing::SUBSCRIPTION_NAME, 'status' => 'ACTIVE',
            'currentPeriodEnd' => now()->addDays(30)->toIso8601String(),
            'lineItems' => [['id' => 'gid://shopify/AppSubscriptionLineItem/7?index=0', 'plan' => ['pricingDetails' => ['__typename' => 'AppUsagePricing']]]],
        ]]]]];
        $this->get('/shopify/billing/return?shop='.self::SHOP.'&charge_id=7')
            ->assertRedirect('https://admin.shopify.com/store/mills/apps/checkout-capture');

        $shop->refresh();
        $this->assertTrue($billing->isActive($shop));
        $this->assertTrue($billing->canServe($shop));

        // New billing cycle → counting restarts. 51 checkouts: one $14 charge, however often we check.
        $this->activations($shop, 51);
        $billing->chargeForUsage($shop);
        $billing->chargeForUsage($shop);
        $this->assertSame(['growth'], UsageCharge::pluck('tier')->all());
        $this->assertEquals(14, UsageCharge::sole()->amount);
        $this->assertSame('charged', UsageCharge::sole()->status);

        // Past 1,000: the $11 top-up to $25.
        $this->activations($shop, 950, offset: 51);
        $billing->chargeForUsage($shop);
        $this->assertEquals([14, 11], UsageCharge::orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertSame(2, collect($this->calls)->filter(fn ($c) => $c === 'appUsageRecordCreate')->count());
    }

    public function test_development_stores_always_get_test_charges(): void
    {
        config(['shopify.billing_test' => false]);
        $this->fakeShopify();
        $this->installedShop();

        $this->overrides = ['partnerDevelopment' => ['shop' => ['plan' => ['partnerDevelopment' => true]]]];
        $this->app('POST', 'billing/subscribe')->assertOk();
        Http::assertSent(fn (Request $r) => str_contains(self::gql($r)['query'] ?? '', 'appSubscriptionCreate')
            && self::gql($r)['variables']['test'] === true);

        $this->overrides = ['partnerDevelopment' => ['shop' => ['plan' => ['partnerDevelopment' => false]]]];
        $this->app('POST', 'billing/subscribe')->assertOk();
        Http::assertSent(fn (Request $r) => str_contains(self::gql($r)['query'] ?? '', 'appSubscriptionCreate')
            && self::gql($r)['variables']['test'] === false);
    }

    public function test_abandoned_carts_of_installed_shops_fire_the_flow_trigger(): void
    {
        $this->fakeShopify();
        $shop = $this->installedShop(['settings' => ['abandoned_carts' => true, 'abandon_after_minutes' => 10]]);
        Capture::factory()->for($shop)->create(['email' => 'dana@example.com']);
        TrackedCart::create([
            'shop_id' => $shop->id, 'email' => 'dana@example.com', 'source' => 'url', 'cart_token' => 'c1',
            'total_cents' => 25800, 'currency' => 'ILS', 'item_count' => 2,
            'items' => [['variant_id' => 4410, 'product_title' => 'Shake', 'quantity' => 2, 'price' => 12900]],
            'last_activity_at' => now()->subMinutes(11),
        ]);

        $this->artisan('carts:detect-abandoned')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_contains(self::gql($r)['query'] ?? '', 'flowTriggerReceive')
            && self::gql($r)['variables']['handle'] === 'cart-abandoned'
            && self::gql($r)['variables']['payload'] === [
                'customer_id' => 987,
                'Customer email' => 'dana@example.com',
                'Recovery url' => 'https://www.mills.co.il/cart/4410:2?checkout%5Bemail%5D=dana%40example.com',
                'Cart total' => '258.00',
                'Currency' => 'ILS',
                'Products' => 'Shake × 2',
                'Source' => 'url',
            ]);
    }

    public function test_embedded_pages_are_framable_only_by_shopify_admin(): void
    {
        config(['shopify.api_key' => 'test-client-id']);

        $this->get('/shopify?shop='.self::SHOP.'&host=abc')
            ->assertOk()
            ->assertSee('<meta name="shopify-api-key" content="test-client-id">', false)
            ->assertSee('app-bridge.js', false)
            ->assertHeader('Content-Security-Policy', 'frame-ancestors https://mills.myshopify.com https://admin.shopify.com;');

        foreach (['settings', 'leads', 'carts'] as $page) {
            $this->get("/shopify/{$page}")->assertOk()->assertSee('<s-page', false);
        }

        $this->get('/shopify/nope')->assertNotFound();
    }

    public function test_the_theme_extension_ships_the_current_widget(): void
    {
        $this->assertFileEquals(
            base_path(SyncShopifyExtension::SOURCE),
            base_path(SyncShopifyExtension::TARGET),
            'Run `php artisan shopify:sync-extension` — the theme extension has an outdated widget.',
        );
    }

    /** GraphQL body as arrays (the client sends variables as a JSON object). */
    private static function gql(Request $request): array
    {
        return json_decode($request->body(), true) ?? [];
    }

    private function activations(Shop $shop, int $count, int $offset = 0): void
    {
        $period = $shop->periodFor();
        Activation::insert(collect(range(1, $count))->map(fn ($i) => [
            'shop_id' => $shop->id, 'email' => 'shopper'.($i + $offset).'@example.com', 'source' => 'popup',
            'period' => $period, 'dedupe_key' => hash('sha256', 'cart:'.($i + $offset)), 'created_at' => now(), 'updated_at' => now(),
        ])->all());
    }
}
