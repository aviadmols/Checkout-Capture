<?php

namespace App\Filament\Resources\Shops\Tables;

use App\Models\Shop;
use App\Support\UsagePlan;
use Illuminate\Database\Eloquent\Builder;
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
                TextColumn::make('activations_count')
                    ->label('Checkouts this month')
                    ->counts(['activations' => fn (Builder $query) => $query->where('period', UsagePlan::period())])
                    ->numeric()
                    ->sortable(),
                TextColumn::make('plan')
                    ->label('Plan')
                    ->state(fn (Shop $record) => UsagePlan::label((int) $record->activations_count))
                    ->badge()
                    ->color(fn (Shop $record) => UsagePlan::forActivations((int) $record->activations_count)['price'] ? 'warning' : 'gray'),
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
