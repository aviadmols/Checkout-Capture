<?php

namespace App\Filament\Resources\Captures\Tables;

use App\Models\Capture;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CapturesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_captured_at', 'desc')
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Name')
                    ->state(fn (Capture $record) => $record->fullName())
                    ->searchable(['first_name', 'last_name'])
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('shop.name')
                    ->label('Shop')
                    ->toggleable(),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'url' => 'Link',
                        'form' => 'Store form',
                        default => 'Popup',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'url' => 'info',
                        'form' => 'success',
                        default => 'gray',
                    }),
                IconColumn::make('accepts_marketing')
                    ->label('Email opt-in')
                    ->boolean(),
                IconColumn::make('accepts_sms')
                    ->label('SMS opt-in')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('cart_total_cents')
                    ->label('Cart')
                    ->money(fn (Capture $record) => $record->currency ?: 'ILS', divideBy: 100)
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('item_count')
                    ->label('Items')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('submissions_count')
                    ->label('Visits')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_captured_at')
                    ->label('Last seen')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
                TextColumn::make('first_captured_at')
                    ->label('First seen')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('shop')
                    ->relationship('shop', 'name'),
                SelectFilter::make('source')
                    ->options(['popup' => 'Popup', 'url' => 'Link', 'form' => 'Store form']),
                TernaryFilter::make('accepts_marketing')
                    ->label('Email opt-in'),
                TernaryFilter::make('accepts_sms')
                    ->label('SMS opt-in'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
