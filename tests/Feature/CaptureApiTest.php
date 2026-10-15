<?php

namespace Tests\Feature;

use App\Models\Capture;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CaptureApiTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create([
            'shop_domain' => 'mills.myshopify.com',
            'allowed_origins' => ['mills.co.il', 'www.mills.co.il', '*.shopifypreview.com'],
        ]);
    }

    private function submit(array $overrides = [], string $origin = 'https://www.mills.co.il')
    {
        return $this->withHeader('Origin', $origin)->postJson('/api/v1/captures', array_replace_recursive([
            'key' => $this->shop->public_key,
            'email' => '  Dana@Example.com ',
            'name' => 'דנה  כהן לוי',
            'phone' => '050-123 4567',
            'accepts_marketing' => true,
            'locale' => 'he',
            'page_url' => 'https://www.mills.co.il/cart',
            'cart' => [
                'token' => 'c1-abc?key=xyz',
                'total_price' => 25900,
                'currency' => 'ils',
                'item_count' => 2,
                'items' => [
                    ['variant_id' => 111, 'product_title' => 'Mills Shake', 'variant_title' => 'שוקולד', 'quantity' => 2, 'price' => 12950, 'junk' => 'x'],
                ],
            ],
        ], $overrides));
    }

    public function test_it_stores_a_new_capture(): void
    {
        $this->submit()->assertCreated()->assertJson(['ok' => true]);

        $capture = Capture::sole();
        $this->assertSame($this->shop->id, $capture->shop_id);
        $this->assertSame('dana@example.com', $capture->email);
        $this->assertSame('דנה', $capture->first_name);
        $this->assertSame('כהן לוי', $capture->last_name);
        $this->assertSame('0501234567', $capture->phone);
        $this->assertTrue($capture->accepts_marketing);
        $this->assertSame(25900, $capture->cart_total_cents);
        $this->assertSame('ILS', $capture->currency);
        $this->assertSame([['variant_id' => 111, 'product_title' => 'Mills Shake', 'variant_title' => 'שוקולד', 'quantity' => 2, 'price' => 12950]], $capture->cart_items);
        $this->assertSame(1, $capture->submissions_count);
    }

    public function test_a_repeat_submission_updates_the_same_row_without_losing_data(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->submit()->assertCreated();

        Carbon::setTestNow('2026-10-03 12:00:00');
        $this->submit([
            'email' => 'dana@example.com',
            'name' => null,
            'phone' => null,
            'accepts_marketing' => false,
            'cart' => ['total_price' => 9900, 'item_count' => 1],
        ])->assertOk();

        $capture = Capture::sole();
        $this->assertSame(2, $capture->submissions_count);
        $this->assertSame('דנה', $capture->first_name, 'name kept when not resent');
        $this->assertTrue($capture->accepts_marketing, 'consent never silently revoked');
        $this->assertSame(9900, $capture->cart_total_cents);
        $this->assertSame('2026-10-01 10:00:00', $capture->first_captured_at->toDateTimeString());
        $this->assertSame('2026-10-03 12:00:00', $capture->last_captured_at->toDateTimeString());
    }

    public function test_same_email_in_two_shops_is_two_rows(): void
    {
        $other = Shop::factory()->create(['allowed_origins' => []]);

        $this->submit()->assertCreated();
        $this->submit(['key' => $other->public_key])->assertCreated();

        $this->assertSame(2, Capture::count());
    }

    public function test_origin_rules(): void
    {
        $this->submit([], 'https://evil.example')->assertForbidden();
        $this->submit([], '')->assertForbidden();
        $this->submit([], 'https://mills.myshopify.com')->assertCreated();
        $this->submit(['email' => 'b@example.com'], 'https://abc123.shopifypreview.com')->assertCreated();
        $this->submit(['email' => 'c@example.com'], 'https://mills.co.il')->assertCreated();
    }

    public function test_unknown_or_switched_off_shop_is_rejected(): void
    {
        $this->submit(['key' => 'cc_nope'])->assertNotFound();

        $this->shop->update(['mode' => Shop::MODE_OFF]);
        $this->submit()->assertNotFound();

        $this->assertSame(0, Capture::count());
    }

    public function test_validation(): void
    {
        $this->submit(['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->submit(['email' => ''])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->submit(['phone' => '12'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->submit(['cart' => ['total_price' => -5]])->assertUnprocessable()->assertJsonValidationErrors('cart.total_price');
        $this->submit(['birthday' => '31/12/1990'])->assertUnprocessable()->assertJsonValidationErrors('birthday');
        $this->submit(['birthday' => now()->addDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('birthday');
        $this->submit(['source' => 'facebook'])->assertUnprocessable()->assertJsonValidationErrors('source');
    }

    public function test_it_stores_the_optional_fields(): void
    {
        $this->submit([
            'name' => null,
            'first_name' => ' דנה ',
            'last_name' => 'כהן',
            'birthday' => '1990-05-17',
            'accepts_sms' => true,
        ])->assertCreated();

        $capture = Capture::sole();
        $this->assertSame('דנה', $capture->first_name);
        $this->assertSame('כהן', $capture->last_name);
        $this->assertSame('1990-05-17', $capture->birthday->toDateString());
        $this->assertTrue($capture->accepts_sms);
        $this->assertSame('popup', $capture->source);
    }

    public function test_emails_typed_into_store_forms_are_saved_with_their_source(): void
    {
        $this->submit(['source' => 'form', 'name' => null, 'phone' => null])->assertCreated();

        $this->assertSame('form', Capture::sole()->source);
    }

    public function test_link_mode_shops_accept_emails_from_links_and_keep_the_first_source(): void
    {
        $this->shop->update(['mode' => Shop::MODE_URL]);

        $this->submit(['source' => 'url', 'name' => null, 'phone' => null, 'cart' => null])->assertCreated();
        $this->assertSame('url', Capture::sole()->source);

        $this->submit(['source' => 'popup'])->assertOk();
        $this->assertSame('url', Capture::sole()->source, 'source records how the lead was first captured');
    }

    public function test_honeypot_is_accepted_but_not_stored(): void
    {
        $this->submit(['website' => 'http://spam.example'])->assertStatus(202);

        $this->assertSame(0, Capture::count());
    }

    public function test_captures_are_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->submit(['email' => "user{$i}@example.com"])->assertSuccessful();
        }

        $this->submit(['email' => 'one-too-many@example.com'])->assertTooManyRequests();
    }

    public function test_cors_preflight_is_answered(): void
    {
        $this->withHeaders([
            'Origin' => 'https://www.mills.co.il',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ])->options('/api/v1/captures')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
