<?php

namespace App\Filament\Resources\Captures;

use App\Filament\Resources\Captures\Pages\ListCaptures;
use App\Filament\Resources\Captures\Pages\ViewCapture;
use App\Filament\Resources\Captures\Schemas\CaptureInfolist;
use App\Filament\Resources\Captures\Tables\CapturesTable;
use App\Models\Capture;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Read-only: captures only ever come in through the storefront API. */
class CaptureResource extends Resource
{
    protected static ?string $model = Capture::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $recordTitleAttribute = 'email';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return CaptureInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CapturesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCaptures::route('/'),
            'view' => ViewCapture::route('/{record}'),
        ];
    }
}
