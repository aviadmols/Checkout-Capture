<?php

namespace App\Filament\Resources\TrackedCarts\Pages;

use App\Filament\Resources\TrackedCarts\TrackedCartResource;
use Filament\Resources\Pages\ListRecords;

class ListTrackedCarts extends ListRecords
{
    protected static string $resource = TrackedCartResource::class;
}
