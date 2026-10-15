<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The popup defaults switched from Hebrew/RTL to English/LTR. Shops that exist already
 * were set up against the Hebrew defaults, so those texts are written into their own
 * settings — their popup looks exactly as before.
 *
 * Also adds tracked_carts: carts of identified shoppers, for abandoned-cart detection.
 */
return new class extends Migration
{
    private const HEBREW_TEXTS = [
        'direction' => 'rtl',
        'title' => 'רגע לפני התשלום',
        'subtitle' => 'השאירו מייל ונשמור לכם את העגלה — כדי שלא תאבדו אותה בדרך.',
        'submit_label' => 'המשך לתשלום',
        'skip_label' => 'המשך בלי למלא',
        'close_label' => 'סגירה',
        'error_email' => 'נא להזין כתובת מייל תקינה',
        'error_required' => 'שדה חובה',
    ];

    private const HEBREW_FIELD_LABELS = [
        'email' => 'אימייל',
        'full_name' => 'שם מלא',
        'first_name' => 'שם פרטי',
        'last_name' => 'שם משפחה',
        'phone' => 'טלפון',
        'birthday' => 'תאריך לידה',
        'accepts_marketing' => 'אשמח לקבל עדכונים ומבצעים במייל',
        'accepts_sms' => 'אשמח לקבל עדכונים ב-SMS',
    ];

    public function up(): void
    {
        DB::table('shops')->orderBy('id')->each(function ($shop) {
            $settings = json_decode($shop->settings ?? '[]', true) ?: [];

            foreach (self::HEBREW_TEXTS as $key => $text) {
                if (blank($settings[$key] ?? null)) {
                    $settings[$key] = $text;
                }
            }

            $fields = collect($settings['fields'] ?? [])->keyBy('key');
            foreach (self::HEBREW_FIELD_LABELS as $key => $label) {
                $field = $fields->get($key, ['key' => $key]);
                if (blank($field['label'] ?? null)) {
                    $field['label'] = $label;
                }
                $fields->put($key, $field);
            }
            $settings['fields'] = $fields->values()->all();

            DB::table('shops')->where('id', $shop->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        });

        Schema::create('tracked_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capture_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('source', 16);
            $table->string('cart_token');
            // active → abandoned after the shop's idle time; checkout once the shopper heads to checkout; emptied.
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('total_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedSmallInteger('item_count')->default(0);
            $table->json('items')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'cart_token']);
            $table->index(['status', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_carts');
        // Pinned texts are left in place: they are what those shops showed anyway.
    }
};
