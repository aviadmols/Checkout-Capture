<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Models\Shop;
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
                    Select::make('settings.direction')
                        ->label('Direction')
                        ->options(['rtl' => 'Right to left (Hebrew)', 'ltr' => 'Left to right'])
                        ->default($d['direction'])
                        ->selectablePlaceholder(false),
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

    public static function snippet(Shop $shop): string
    {
        $src = rtrim(config('app.url'), '/').'/js/checkout-capture.js?v=1';

        // Skipped inside the theme editor so the popup never hijacks clicks while editing.
        return <<<LIQUID
        {%- unless request.design_mode -%}<script src="{$src}" data-key="{$shop->public_key}"{%- if customer %} data-customer-email="{{ customer.email | escape }}"{%- endif %} defer></script>{%- endunless -%}
        LIQUID;
    }
}
