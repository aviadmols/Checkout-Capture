<?php

namespace App\Filament\Resources\TrackedCarts;

use App\Filament\Resources\TrackedCarts\Pages\ListTrackedCarts;
use App\Models\TrackedCart;
use App\Services\AbandonedCartWebhook;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Carts of recognised shoppers; read-only. Abandoned ones carry a link that restores the cart. */
class TrackedCartResource extends Resource
{
    protected static ?string $model = TrackedCart::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Abandoned carts';

    protected static ?string $modelLabel = 'cart';

    protected static ?string $pluralModelLabel = 'abandoned carts';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_activity_at', 'desc')
            ->columns([
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        TrackedCart::ABANDONED => 'danger',
                        TrackedCart::CHECKOUT => 'success',
                        TrackedCart::ACTIVE => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('items')
                    ->label('Products')
                    ->state(fn (TrackedCart $record) => collect($record->items)
                        ->map(fn (array $item) => trim(($item['product_title'] ?? '').' × '.($item['quantity'] ?? 1)))
                        ->implode(', '))
                    ->wrap()
                    ->limit(80),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->money(fn (TrackedCart $record) => $record->currency ?: 'ILS', divideBy: 100)
                    ->sortable(),
                TextColumn::make('shop.name')->label('Shop')->toggleable(),
                TextColumn::make('last_activity_at')->label('Last activity')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('abandoned_at')->label('Abandoned')->since()->dateTimeTooltip()->sortable()->placeholder('—'),
                TextColumn::make('webhook_status')
                    ->label('Webhook')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? ucfirst($state) : null)
                    ->color(fn (?string $state) => $state === 'sent' ? 'success' : 'danger')
                    ->tooltip(fn (TrackedCart $record) => $record->webhook_error ?: ($record->webhook_response_code ? "HTTP {$record->webhook_response_code}" : null))
                    ->placeholder('—'),
                TextColumn::make('recovery_url')
                    ->label('Recovery link')
                    ->state(fn (TrackedCart $record) => $record->recoveryUrl())
                    ->fontFamily(FontFamily::Mono)
                    ->limit(32)
                    ->copyable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        TrackedCart::ABANDONED => 'Abandoned',
                        TrackedCart::ACTIVE => 'Active',
                        TrackedCart::CHECKOUT => 'Went to checkout',
                        TrackedCart::EMPTIED => 'Emptied',
                    ])
                    ->default(TrackedCart::ABANDONED),
                SelectFilter::make('shop')->relationship('shop', 'name'),
            ])
            ->recordActions([
                Action::make('resendWebhook')
                    ->label('Resend webhook')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (TrackedCart $record) => $record->status === TrackedCart::ABANDONED && filled($record->shop?->webhook_url))
                    ->action(function (TrackedCart $record, AbandonedCartWebhook $webhook) {
                        $ok = $webhook->deliver($record);

                        Notification::make()
                            ->title($ok ? 'Webhook delivered' : 'Webhook failed')
                            ->body($ok ? null : $record->webhook_error)
                            ->status($ok ? 'success' : 'danger')
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrackedCarts::route('/'),
        ];
    }
}
