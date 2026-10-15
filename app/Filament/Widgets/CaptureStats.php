<?php

namespace App\Filament\Widgets;

use App\Models\Activation;
use App\Models\Capture;
use App\Support\UsagePlan;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CaptureStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $today = Capture::query()->where('first_captured_at', '>=', today())->count();
        $week = Capture::query()->where('first_captured_at', '>=', today()->subDays(6))->count();
        $total = Capture::query()->count();
        $optedIn = Capture::query()->where('accepts_marketing', true)->count();
        $checkouts = Activation::query()->where('period', UsagePlan::period())->count();

        return [
            Stat::make('Checkouts created this month', number_format($checkouts))
                ->description('Carts sent to checkout with a captured email'),
            Stat::make('New today', number_format($today)),
            Stat::make('New in the last 7 days', number_format($week)),
            Stat::make('All captured emails', number_format($total)),
            Stat::make('Marketing opt-in', $total ? round($optedIn / $total * 100).'%' : '—')
                ->description(number_format($optedIn).' contacts'),
        ];
    }
}
