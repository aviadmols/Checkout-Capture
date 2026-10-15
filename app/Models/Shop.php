<?php

namespace App\Models;

use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

#[Fillable(['name', 'shop_domain', 'allowed_origins', 'public_key', 'storefront_access_token', 'mode', 'settings'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use HasFactory;

    /** Shoppers with no known email get the popup form before checkout. */
    public const MODE_POPUP = 'popup';

    /** No form at all: only an email that arrives in the link (?email=…) is captured and attached. */
    public const MODE_URL = 'url';

    /** Widget steps aside completely; the API refuses captures. */
    public const MODE_OFF = 'off';

    public const MODES = [self::MODE_POPUP, self::MODE_URL, self::MODE_OFF];

    public const SOURCES = ['popup', 'url'];

    public const DESIGNS = ['classic', 'split'];

    /**
     * Every field the popup can show. `settings.fields` stores the shop's order,
     * on/off state, required flag and label overrides; anything missing falls back to these.
     */
    public const FIELDS = [
        'email' => ['type' => 'email', 'label' => 'אימייל', 'enabled' => true, 'required' => true],
        'full_name' => ['type' => 'text', 'label' => 'שם מלא', 'enabled' => true, 'required' => false],
        'first_name' => ['type' => 'text', 'label' => 'שם פרטי', 'enabled' => false, 'required' => false],
        'last_name' => ['type' => 'text', 'label' => 'שם משפחה', 'enabled' => false, 'required' => false],
        'phone' => ['type' => 'tel', 'label' => 'טלפון', 'enabled' => false, 'required' => false],
        'birthday' => ['type' => 'date', 'label' => 'תאריך לידה', 'enabled' => false, 'required' => false],
        'accepts_marketing' => ['type' => 'checkbox', 'label' => 'אשמח לקבל עדכונים ומבצעים במייל', 'enabled' => true, 'required' => false],
        'accepts_sms' => ['type' => 'checkbox', 'label' => 'אשמח לקבל עדכונים ב-SMS', 'enabled' => false, 'required' => false],
    ];

    /** Admin-facing names for the field picker. */
    public const FIELD_NAMES = [
        'email' => 'Email (always on)',
        'full_name' => 'Full name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'phone' => 'Phone',
        'birthday' => 'Birthday',
        'accepts_marketing' => 'Email marketing consent',
        'accepts_sms' => 'SMS marketing consent',
    ];

    /** Popup defaults. Anything stored in `settings` overrides these key by key. */
    public const DEFAULT_SETTINGS = [
        'design' => 'classic',
        'direction' => 'rtl',
        'eyebrow' => '',
        'title' => 'רגע לפני התשלום',
        'subtitle' => 'השאירו מייל ונשמור לכם את העגלה — כדי שלא תאבדו אותה בדרך.',
        'image_url' => '',
        'submit_label' => 'המשך לתשלום',
        'skip_label' => 'המשך בלי למלא',
        'privacy_note' => '',
        'error_email' => 'נא להזין כתובת מייל תקינה',
        'error_required' => 'שדה חובה',
        'allow_skip' => false,
        // Read the email from links like ?email=dana@example.com. Always on in url mode.
        'url_capture' => true,
        'url_param' => 'email',
        'accent_color' => '#000000',
        'extra_selectors' => '',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Shop $shop) {
            $shop->public_key ??= static::generatePublicKey();
            $shop->mode ??= self::MODE_POPUP;
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

    /** Field list in catalogue shape, for a new shop's form. */
    public static function defaultFields(): array
    {
        return collect(self::FIELDS)
            ->map(fn (array $field, string $key) => ['key' => $key, 'enabled' => $field['enabled'], 'required' => $field['required'], 'label' => null])
            ->values()
            ->all();
    }

    public function captures(): HasMany
    {
        return $this->hasMany(Capture::class);
    }

    public function isOff(): bool
    {
        return $this->mode === self::MODE_OFF;
    }

    /** @return array<string, mixed> */
    public function resolvedSettings(): array
    {
        $stored = array_filter($this->settings ?? [], fn ($value) => $value !== null && $value !== '');
        $settings = array_merge(self::DEFAULT_SETTINGS, Arr::only($stored, array_keys(self::DEFAULT_SETTINGS)));

        if (! in_array($settings['design'], self::DESIGNS, true)) {
            $settings['design'] = self::DEFAULT_SETTINGS['design'];
        }

        if ($this->mode === self::MODE_URL) {
            $settings['url_capture'] = true;
        }

        $settings['fields'] = array_values(array_filter(
            $this->resolvedFields(),
            fn (array $field) => $field['enabled'],
        ));

        return $settings;
    }

    /**
     * The shop's field list in its saved order, with unknown keys dropped and
     * fields added since the shop was saved appended (with their defaults).
     *
     * @return list<array{key: string, type: string, label: string, enabled: bool, required: bool}>
     */
    public function resolvedFields(): array
    {
        $stored = collect($this->settings['fields'] ?? [])
            ->filter(fn ($field) => is_array($field) && isset(self::FIELDS[$field['key'] ?? null]))
            ->keyBy('key');

        $order = $stored->keys()->merge(array_diff(array_keys(self::FIELDS), $stored->keys()->all()));

        return $order->map(function (string $key) use ($stored) {
            $default = self::FIELDS[$key];
            $saved = $stored->get($key, []);
            $isCheckbox = $default['type'] === 'checkbox';

            $field = [
                'key' => $key,
                'type' => $default['type'],
                'label' => filled($saved['label'] ?? null) ? $saved['label'] : $default['label'],
                'enabled' => (bool) ($saved['enabled'] ?? $default['enabled']),
                // A consent box must be a free choice, so it can never be required.
                'required' => ! $isCheckbox && (bool) ($saved['required'] ?? $default['required']),
            ];

            if ($key === 'email') {
                $field['enabled'] = $field['required'] = true;
            }

            return $field;
        })->values()->all();
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

    /** Public storefront host for links: the first non-wildcard allowed origin, else the myshopify domain. */
    public function storefrontHost(): string
    {
        $host = collect($this->allowed_origins ?? [])
            ->map(fn ($entry) => static::normalizeHost($entry))
            ->first(fn ($entry) => $entry !== '' && ! Str::contains($entry, '*'));

        return $host ?: $this->shop_domain;
    }

    /** Example campaign link; `{{ email }}` is the merge tag the email/SMS tool fills in. */
    public function campaignLinkExample(): string
    {
        $param = $this->resolvedSettings()['url_param'];

        return 'https://'.$this->storefrontHost().'/?'.$param.'={{ email }}';
    }

    /** Payload the storefront widget boots with. Only public values. */
    public function widgetConfig(): array
    {
        return [
            'mode' => $this->mode,
            'shopDomain' => $this->shop_domain,
            'storefrontToken' => $this->storefront_access_token,
            'settings' => $this->resolvedSettings(),
        ];
    }
}
