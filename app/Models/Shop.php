<?php

namespace App\Models;

use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

#[Fillable(['name', 'shop_domain', 'allowed_origins', 'public_key', 'storefront_access_token', 'is_active', 'settings'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory;

    /**
     * Popup defaults. Anything stored in `settings` overrides these key by key.
     * Field modes: required | optional | hidden.
     */
    public const DEFAULT_SETTINGS = [
        'direction' => 'rtl',
        'title' => 'רגע לפני התשלום',
        'subtitle' => 'השאירו מייל ונשמור לכם את העגלה — כדי שלא תאבדו אותה בדרך.',
        'email_label' => 'אימייל',
        'name_label' => 'שם מלא',
        'phone_label' => 'טלפון',
        'submit_label' => 'המשך לתשלום',
        'skip_label' => 'המשך בלי למלא',
        'consent_label' => 'אשמח לקבל עדכונים ומבצעים במייל',
        'privacy_note' => '',
        'error_email' => 'נא להזין כתובת מייל תקינה',
        'error_required' => 'שדה חובה',
        'name_field' => 'optional',
        'phone_field' => 'hidden',
        'consent_enabled' => true,
        'consent_checked' => false,
        'allow_skip' => false,
        'accent_color' => '#000000',
        'extra_selectors' => '',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Shop $shop) {
            $shop->public_key ??= static::generatePublicKey();
        });

        static::saving(function (Shop $shop) {
            $shop->shop_domain = static::normalizeHost($shop->shop_domain);
        });
    }

    public static function generatePublicKey(): string
    {
        return 'cc_'.Str::lower(Str::random(32));
    }

    /** Strip scheme, path and trailing dot so "https://Foo.com/x" becomes "foo.com". */
    public static function normalizeHost(?string $value): string
    {
        $value = Str::lower(trim((string) $value));
        $host = parse_url(Str::contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);

        return rtrim($host ?: $value, '.');
    }

    public function captures(): HasMany
    {
        return $this->hasMany(Capture::class);
    }

    /** @return array<string, mixed> */
    public function resolvedSettings(): array
    {
        $stored = array_filter($this->settings ?? [], fn ($value) => $value !== null);

        return array_merge(self::DEFAULT_SETTINGS, Arr::only($stored, array_keys(self::DEFAULT_SETTINGS)));
    }

    /**
     * Origin check for browser requests. The myshopify domain is always allowed;
     * entries may use wildcards ("*.shopifypreview.com"). No list configured = allow all.
     */
    public function allowsOrigin(?string $origin): bool
    {
        $patterns = array_filter(array_map(
            fn ($entry) => static::normalizeHost($entry),
            $this->allowed_origins ?? [],
        ));

        if ($patterns === []) {
            return true;
        }

        if (! $origin) {
            return false;
        }

        $host = static::normalizeHost($origin);
        $patterns[] = $this->shop_domain;

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    /** Payload the storefront widget boots with. Only public values. */
    public function widgetConfig(): array
    {
        return [
            'shopDomain' => $this->shop_domain,
            'storefrontToken' => $this->storefront_access_token,
            'settings' => $this->resolvedSettings(),
        ];
    }
}
