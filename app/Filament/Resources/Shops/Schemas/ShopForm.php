<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Models\Shop;
use Filament\Forms\Components\ColorPicker;
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
                        Tab::make('Popup')->schema(self::popupTab()),
                        Tab::make('Install')->schema(self::installTab())->visibleOn('edit'),
                    ]),
            ]);
    }

    private static function storeTab(): array
    {
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
            TagsInput::make('allowed_origins')
                ->label('Storefront domains')
                ->placeholder('mystore.co.il')
                ->helperText('Hosts allowed to send captures (the myshopify domain is always allowed). Wildcards work: *.shopifypreview.com. Leave empty to allow any origin.'),
            TextInput::make('storefront_access_token')
                ->label('Storefront API token (public)')
                ->helperText('Optional. With it, the email is set on the cart\'s buyer identity so Shopify prefills it in checkout. Create one via the Headless channel (or a custom app) with the unauthenticated_write_checkouts scope.')
                ->maxLength(255),
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('When off, the widget steps aside and checkout works as if it isn\'t installed.')
                ->default(true),
        ];
    }

    private static function popupTab(): array
    {
        $d = Shop::DEFAULT_SETTINGS;
        $fieldModes = ['required' => 'Required', 'optional' => 'Optional', 'hidden' => 'Hidden'];

        return [
            Section::make('Texts')
                ->description('Leave a field empty to use the default shown in grey.')
                ->columns(2)
                ->schema([
                    Select::make('settings.direction')
                        ->label('Direction')
                        ->options(['rtl' => 'Right to left (Hebrew)', 'ltr' => 'Left to right'])
                        ->placeholder('Default: rtl'),
                    TextInput::make('settings.title')->label('Title')->placeholder($d['title']),
                    Textarea::make('settings.subtitle')->label('Subtitle')->placeholder($d['subtitle'])->rows(2)->columnSpanFull(),
                    TextInput::make('settings.email_label')->label('Email label')->placeholder($d['email_label']),
                    TextInput::make('settings.name_label')->label('Name label')->placeholder($d['name_label']),
                    TextInput::make('settings.phone_label')->label('Phone label')->placeholder($d['phone_label']),
                    TextInput::make('settings.submit_label')->label('Submit button')->placeholder($d['submit_label']),
                    TextInput::make('settings.skip_label')->label('Skip link')->placeholder($d['skip_label']),
                    TextInput::make('settings.consent_label')->label('Marketing consent text')->placeholder($d['consent_label']),
                    TextInput::make('settings.error_email')->label('Invalid email message')->placeholder($d['error_email']),
                    TextInput::make('settings.error_required')->label('Required field message')->placeholder($d['error_required']),
                    Textarea::make('settings.privacy_note')->label('Small print under the button')->rows(2)->columnSpanFull(),
                ]),
            Section::make('Behaviour')
                ->columns(2)
                ->schema([
                    Select::make('settings.name_field')->label('Name field')->options($fieldModes)->default($d['name_field'])->selectablePlaceholder(false),
                    Select::make('settings.phone_field')->label('Phone field')->options($fieldModes)->default($d['phone_field'])->selectablePlaceholder(false),
                    Toggle::make('settings.consent_enabled')->label('Show marketing consent checkbox')->default($d['consent_enabled']),
                    Toggle::make('settings.consent_checked')->label('Consent pre-checked')->default($d['consent_checked'])
                        ->helperText('Israeli anti-spam law (תיקון 40) requires active opt-in — keep this off.'),
                    Toggle::make('settings.allow_skip')->label('Allow continuing without filling')->default($d['allow_skip']),
                    ColorPicker::make('settings.accent_color')->label('Button colour')->default($d['accent_color'])
                        ->rule('regex:/^#[0-9a-f]{3,8}$/i'),
                    TextInput::make('settings.extra_selectors')->label('Extra checkout selectors (CSS)')
                        ->placeholder('.my-theme-checkout-btn, #CustomCheckout')
                        ->helperText('Only needed when the theme\'s checkout button isn\'t a name="checkout" button or a /checkout link.')
                        ->columnSpanFull(),
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
