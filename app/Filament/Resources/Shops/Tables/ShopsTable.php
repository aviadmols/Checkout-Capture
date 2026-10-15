<?php

namespace App\Filament\Resources\Shops\Tables;

use App\Models\Shop;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('shop_domain')
                    ->label('Domain')
                    ->searchable(),
                TextColumn::make('public_key')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('captures_count')
                    ->label('Captures')
                    ->counts('captures')
                    ->sortable(),
                TextColumn::make('mode')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Shop::MODE_POPUP => 'Popup',
                        Shop::MODE_URL => 'Link only',
                        default => 'Off',
                    })
                    ->color(fn (string $state) => match ($state) {
                        Shop::MODE_POPUP => 'success',
                        Shop::MODE_URL => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
