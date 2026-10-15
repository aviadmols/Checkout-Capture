<?php

namespace App\Filament\Resources\Shops\Tables;

use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
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
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
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
