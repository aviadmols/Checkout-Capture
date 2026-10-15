<?php

namespace App\Filament\Resources\Shops\Tables;

use App\Models\Shop;
use App\Support\UsagePlan;
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
                TextColumn::make('checkouts_this_period')
                    ->label('Checkouts this period')
                    ->state(fn (Shop $record) => $record->activationCount())
                    ->numeric(),
                TextColumn::make('plan')
                    ->label('Plan')
                    ->state(fn (Shop $record) => UsagePlan::label($record->activationCount()))
                    ->badge()
                    ->color(fn (Shop $record) => UsagePlan::forActivations($record->activationCount())['price'] ? 'warning' : 'gray'),
                TextColumn::make('installed_at')
                    ->label('Shopify app')
                    ->state(fn (Shop $record) => match (true) {
                        $record->isInstalled() => 'Installed',
                        $record->uninstalled_at !== null => 'Uninstalled',
                        default => 'Manual',
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Installed' => 'success',
                        'Uninstalled' => 'danger',
                        default => 'gray',
                    }),
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
