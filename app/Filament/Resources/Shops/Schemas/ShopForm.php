<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Models\Shop;
use App\Support\UsagePlan;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class ShopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Store')->schema(self::storeTab()),
                        Tab::make('Popup')
                            ->schema(self::popupTab())
                            ->visible(fn (Get $get) => $get('mode') === Shop::MODE_POPUP),
                        Tab::make('Install')->schema(self::installTab())->visibleOn('edit'),
                        Tab::make('Usage')->schema(self::usageTab())->visibleOn('edit'),
                    ]),
            ]);
    }

    private static function storeTab(): array
    {
        $d = Shop::DEFAULT_SETTINGS;

        return [
            Grid::make(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('shop_domain')
                    ->label('myshopify domain')
                    ->placeholder('my-store.myshopify.com')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->rule('regex:/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/i')
                    ->dehydrateStateUsing(fn (?string $state) => Shop::normalizeHost($state)),
            ]),
            Radio::make('mode')
                ->label('How it works')
                ->options([
                    Shop::MODE_POPUP => 'Popup form',
                    Shop::MODE_URL => 'Email from link only — no form',
                    Shop::MODE_OFF => 'Off',
                ])
                ->descriptions([
                    Shop::MODE_POPUP => 'Shoppers we don\'t know yet get the popup before checkout.',
                    Shop::MODE_URL => 'Nothing is shown. When a shopper arrives from a link with their email (e.g. a campaign: ?email=…), the email is saved and attached to their order at checkout.',
                    Shop::MODE_OFF => 'The widget steps aside; checkout works as if it isn\'t installed.',
                ])
                ->default(Shop::MODE_POPUP)
                ->required()
                ->live(),
            Grid::make(2)
                ->visible(fn (Get $get) => $get('mode') !== Shop::MODE_OFF)
                ->schema([
                    Toggle::make('settings.url_capture')
                        ->label('Also read the email from links')
                        ->helperText('Shoppers who arrive with ?email=… skip the popup.')
                        ->default($d['url_capture'])
                        ->visible(fn (Get $get) => $get('mode') === Shop::MODE_POPUP),
                    TextInput::make('settings.url_param')
                        ->label('Link parameter')
                        ->prefix('?')
                        ->suffix('=…')
                        ->placeholder($d['url_param'])
                        ->helperText('Use the merge tag of your email/SMS tool, e.g. ?email={{ email }}.')
                        ->rule('regex:/^[A-Za-z0-9_\-\[\]]+$/')
                        ->maxLength(40),
                ]),
            Section::make('Recognising shoppers')
                ->visible(fn (Get $get) => $get('mode') !== Shop::MODE_OFF)
                ->columns(2)
                ->schema([
                    Toggle::make('settings.form_capture')
                        ->label('Pick up emails typed into the store\'s own forms')
                        ->helperText('Newsletter, contact or account forms. The email is saved in a cookie and as a lead, and attached at checkout.')
                        ->default($d['form_capture'])
                        ->columnSpanFull(),
                    Toggle::make('settings.abandoned_carts')
                        ->label('Track abandoned carts of recognised shoppers')
                        ->helperText('When a shopper we know (from a link, the popup or a store form) has products in the cart and goes idle without heading to checkout, the cart is marked abandoned with a link that restores it.')
                        ->default($d['abandoned_carts'])
                        ->live(),
                    TextInput::make('settings.abandon_after_minutes')
                        ->label('Abandoned after (minutes idle)')
                        ->numeric()
                        ->integer()
                        ->minValue(5)
                        ->maxValue(1440)
                        ->placeholder((string) $d['abandon_after_minutes'])
                        ->visible(fn (Get $get) => (bool) $get('settings.abandoned_carts')),
                    TextInput::make('webhook_url')
                        ->label('Send abandoned carts to (webhook URL)')
                        ->placeholder('https://hooks.example.com/abandoned-cart')
                        ->url()
                        ->rule('starts_with:https://')
                        ->maxLength(2048)
                        ->helperText('Each abandoned cart is POSTed here as JSON — customer, products and a recovery link — signed with the secret in the Install tab. Your own tool (n8n, Make, Zapier, your ESP) sends the message; Checkout Capture never emails shoppers.')
                        ->visible(fn (Get $get) => (bool) $get('settings.abandoned_carts'))
                        ->columnSpanFull(),
                    Toggle::make('settings.identify_marketing_apps')
                        ->label('Identify recognised shoppers to Klaviyo / Omnisend')
                        ->helperText('If the store runs Klaviyo or Omnisend, they are told the shopper\'s email, so their own abandoned-cart flows (Added to Cart, Started Checkout) can send for shoppers who came from a link or a store form.')
                        ->default($d['identify_marketing_apps'])
                        ->columnSpanFull(),
                ]),
            TagsInput::make('allowed_origins')
                ->label('Storefront domains')
                ->placeholder('mystore.co.il')
                ->helperText('Hosts allowed to send captures (the myshopify domain is always allowed). Wildcards work: *.shopifypreview.com. Leave empty to allow any origin.'),
            TextInput::make('storefront_access_token')
                ->label('Storefront API token (public)')
                ->helperText('Optional. With it, the email is set on the cart\'s buyer identity so Shopify prefills it in checkout. Create one via the Headless channel (or a custom app) with the unauthenticated_write_checkouts scope.')
                ->maxLength(255),
            TextInput::make('settings.extra_selectors')
                ->label('Extra checkout selectors (CSS)')
                ->placeholder('.my-theme-checkout-btn, #CustomCheckout')
                ->helperText('Only needed when the theme\'s checkout button isn\'t a name="checkout" button or a /checkout link.'),
        ];
    }

    private static function popupTab(): array
    {
        $d = Shop::DEFAULT_SETTINGS;

        return [
            Section::make('Design')
                ->columns(2)
                ->schema([
                    Radio::make('settings.design')
                        ->label('Layout')
                        ->options(['classic' => 'Classic', 'split' => 'Split with image'])
                        ->descriptions([
                            'classic' => 'Centred card with the title above the form. Bottom sheet on mobile.',
                            'split' => 'Brand image beside the form (on top on mobile). Without an image the panel shows the title in large type.',
                        ])
                        ->default($d['design'])
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('settings.image_url')
                        ->label('Image URL')
                        ->url()
                        ->rule('starts_with:https://')
                        ->helperText('Upload in Shopify → Content → Files and paste the link. Portrait images work best.')
                        ->visible(fn (Get $get) => $get('settings.design') === 'split')
                        ->columnSpanFull(),
                    ColorPicker::make('settings.accent_color')->label('Button and panel colour')->default($d['accent_color'])
                        ->rule('regex:/^#[0-9a-f]{3,8}$/i'),
                ]),
            Section::make('Language')
                ->key('language')
                ->description('Every text in the popup can be translated below. These buttons fill all texts and field labels at once — you can still edit them after.')
                ->headerActions([
                    Action::make('fillEnglish')
                        ->label('Fill in English')
                        ->color('gray')
                        ->action(fn (Get $get, Set $set) => self::fillTexts($get, $set, 'en')),
                    Action::make('fillHebrew')
                        ->label('Fill in Hebrew')
                        ->color('gray')
                        ->action(fn (Get $get, Set $set) => self::fillTexts($get, $set, 'he')),
                ])
                ->schema([
                    Radio::make('settings.direction')
                        ->label('Text direction')
                        ->options(['ltr' => 'Left to right (English, most languages)', 'rtl' => 'Right to left (Hebrew, Arabic)'])
                        ->default($d['direction'])
                        ->inline(),
                ]),
            Section::make('Fields')
                ->description('Choose which fields appear, which are required, their labels and order (drag to reorder). Leave a label empty to use the default shown in grey.')
                ->schema([
                    Repeater::make('settings.fields')
                        ->hiddenLabel()
                        ->schema([
                            Hidden::make('key'),
                            Toggle::make('enabled')
                                ->label('Show')
                                ->disabled(fn (Get $get) => $get('key') === 'email')
                                ->dehydrated()
                                ->inline(false),
                            Toggle::make('required')
                                ->label('Required')
                                ->disabled(fn (Get $get) => $get('key') === 'email')
                                ->dehydrated()
                                ->visible(fn (Get $get) => (Shop::FIELDS[$get('key')]['type'] ?? null) !== 'checkbox')
                                ->inline(false),
                            TextInput::make('label')
                                ->label('Label')
                                ->placeholder(fn (Get $get) => Shop::FIELDS[$get('key')]['label'] ?? null)
                                ->maxLength(200),
                        ])
                        ->columns(['default' => 1, 'md' => 3])
                        ->itemLabel(fn (array $state) => Shop::FIELD_NAMES[$state['key'] ?? ''] ?? null)
                        ->default(Shop::defaultFields())
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable()
                        ->collapsible(),
                ]),
            Section::make('Texts')
                ->description('Leave a field empty to use the default shown in grey.')
                ->columns(2)
                ->schema([
                    TextInput::make('settings.eyebrow')->label('Small line above the title')->placeholder('Optional'),
                    TextInput::make('settings.title')->label('Title')->placeholder($d['title']),
                    Textarea::make('settings.subtitle')->label('Subtitle')->placeholder($d['subtitle'])->rows(2)->columnSpanFull(),
                    TextInput::make('settings.submit_label')->label('Submit button')->placeholder($d['submit_label']),
                    TextInput::make('settings.skip_label')->label('Skip link')->placeholder($d['skip_label']),
                    TextInput::make('settings.close_label')->label('Close button (screen readers)')->placeholder($d['close_label']),
                    TextInput::make('settings.error_email')->label('Invalid email message')->placeholder($d['error_email']),
                    TextInput::make('settings.error_required')->label('Required field message')->placeholder($d['error_required']),
                    Textarea::make('settings.privacy_note')->label('Small print under the button')->rows(2)->columnSpanFull(),
                ]),
            Section::make('Behaviour')
                ->schema([
                    Toggle::make('settings.allow_skip')->label('Allow continuing without filling')->default($d['allow_skip']),
                ]),
        ];
    }

    private static function installTab(): array
    {
        return [
            TextEntry::make('public_key')
                ->label('Public key')
                ->fontFamily(FontFamily::Mono)
                ->copyable(),
            TextEntry::make('install_snippet')
                ->label('Theme snippet — paste into layout/theme.liquid just before </body>')
                ->state(fn (?Shop $record) => $record ? self::snippet($record) : null)
                ->fontFamily(FontFamily::Mono)
                ->copyable()
                ->columnSpanFull(),
            TextEntry::make('webhook_secret')
                ->label('Webhook signing secret')
                ->helperText('Verify requests: X-Checkout-Capture-Signature = "sha256=" + HMAC-SHA256(raw body, this secret).')
                ->fontFamily(FontFamily::Mono)
                ->copyable()
                ->visible(fn (?Shop $record) => filled($record?->webhook_url))
                ->columnSpanFull(),
            TextEntry::make('campaign_link')
                ->label('Campaign link — the email is read from it and attached at checkout')
                ->state(fn (?Shop $record) => $record?->campaignLinkExample())
                ->helperText('Any page works, not only the home page. Replace {{ email }} with your email/SMS tool\'s merge tag.')
                ->fontFamily(FontFamily::Mono)
                ->copyable()
                ->visible(fn (?Shop $record) => $record && ! $record->isOff() && $record->resolvedSettings()['url_capture'])
                ->columnSpanFull(),
        ];
    }

    /** Fills every popup text and field label with the English defaults or the Hebrew preset. */
    private static function fillTexts(Get $get, Set $set, string $language): void
    {
        $texts = $language === 'he'
            ? Shop::HEBREW_TEXTS
            : Arr::only(Shop::DEFAULT_SETTINGS, array_keys(Shop::HEBREW_TEXTS));

        foreach ($texts as $key => $value) {
            $set("settings.{$key}", $value);
        }

        $set('settings.fields', collect($get('settings.fields') ?? [])
            ->map(fn (array $field) => [
                ...$field,
                'label' => $language === 'he' ? (Shop::HEBREW_FIELD_LABELS[$field['key']] ?? null) : null,
            ])
            ->all());
    }

    /**
     * Checkouts created = activations: carts the module attached an email to on the way
     * to checkout (one per cart per month; logged-in customers aren't counted).
     */
    private static function usageTab(): array
    {
        $tiers = collect(UsagePlan::TIERS)
            ->map(fn (array $t, int $i) => $t['up_to']
                ? ($i === 0 ? "up to {$t['up_to']}" : 'up to '.number_format($t['up_to']))." checkouts: {$t['name']} \${$t['price']}"
                : 'above that: '."{$t['name']} \${$t['price']}")
            ->implode(' · ');

        // Shops billed through Shopify follow their 30-day billing cycle; others use calendar months.
        $period = fn (?Shop $record, bool $previous) => $record
            ? ($previous ? $record->previousPeriod() : $record->periodFor())
            : UsagePlan::period();

        $usage = fn (bool $previous) => [
            TextEntry::make('usage_'.($previous ? 'previous' : 'current').'_count')
                ->label(fn (?Shop $record) => ($previous ? 'Previous' : 'Current').' period ('.$period($record, $previous).') — checkouts created')
                ->state(fn (?Shop $record) => $record?->activationCount($period($record, $previous)) ?? 0)
                ->numeric(),
            TextEntry::make('usage_'.($previous ? 'previous' : 'current').'_plan')
                ->label('Plan')
                ->state(fn (?Shop $record) => UsagePlan::label($record?->activationCount($period($record, $previous)) ?? 0))
                ->badge(),
        ];

        return [
            Grid::make(2)->schema([...$usage(false), ...$usage(true)]),
            TextEntry::make('orders_received')
                ->label('Orders received from captured shoppers (current period)')
                ->state(function (?Shop $record) {
                    if (! $record) {
                        return null;
                    }

                    $start = strlen($record->periodFor()) === 10
                        ? Carbon::parse($record->periodFor())
                        : Carbon::parse($record->periodFor().'-01');
                    $orders = $record->orders()->where('ordered_at', '>=', $start);
                    $count = (clone $orders)->count();
                    $revenue = (clone $orders)->sum('total_cents') / 100;

                    return $count ? number_format($count).' orders · '.number_format($revenue, 2).' revenue' : 'None yet';
                })
                ->helperText('Counted from Shopify\'s orders/create webhook — needs the app installed through Shopify.'),
            TextEntry::make('pricing')
                ->label('Pricing')
                ->state($tiers)
                ->helperText('A checkout counts once per cart per month, when the module attached an email and sent the shopper to checkout. Logged-in customers are not counted.'),
        ];
    }

    public static function snippet(Shop $shop): string
    {
        $src = rtrim(config('app.url'), '/').'/js/checkout-capture.js?v=1';

        // Skipped inside the theme editor so the popup never hijacks clicks while editing.
        return <<<LIQUID
        {%- unless request.design_mode -%}<script src="{$src}" data-key="{$shop->public_key}"{%- if customer %} data-customer-email="{{ customer.email | escape }}"{%- endif %} defer></script>{%- endunless -%}
        LIQUID;
    }
}
