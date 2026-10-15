<?php

namespace App\Filament\Resources\Captures\Schemas;

use App\Models\Capture;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CaptureInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('email')->copyable(),
                        TextEntry::make('name')->state(fn (Capture $record) => $record->fullName())->placeholder('—'),
                        TextEntry::make('phone')->placeholder('—')->copyable(),
                        IconEntry::make('accepts_marketing')->label('Marketing consent')->boolean(),
                        TextEntry::make('shop.name')->label('Shop'),
                        TextEntry::make('locale')->placeholder('—'),
                    ]),
                Section::make('Cart (latest submission)')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('cart_total_cents')
                            ->label('Total')
                            ->money(fn (Capture $record) => $record->currency ?: 'ILS', divideBy: 100)
                            ->placeholder('—'),
                        TextEntry::make('item_count')->label('Items')->placeholder('—'),
                        TextEntry::make('cart_token')->label('Cart token')->placeholder('—')->limit(24)->copyable(),
                        RepeatableEntry::make('cart_items')
                            ->hiddenLabel()
                            ->columns(4)
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('product_title')->label('Product'),
                                TextEntry::make('variant_title')->label('Variant')->placeholder('—'),
                                TextEntry::make('quantity')->label('Qty'),
                                TextEntry::make('price')
                                    ->label('Unit price')
                                    ->money(fn (Capture $record) => $record->currency ?: 'ILS', divideBy: 100),
                            ]),
                    ]),
                Section::make('Activity')
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('submissions_count')->label('Submissions'),
                        TextEntry::make('first_captured_at')->label('First seen')->dateTime(),
                        TextEntry::make('last_captured_at')->label('Last seen')->dateTime(),
                        TextEntry::make('page_url')->label('Page')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('ip_address')->label('IP')->placeholder('—'),
                        TextEntry::make('user_agent')->placeholder('—')->columnSpan(2),
                    ]),
            ]);
    }
}
