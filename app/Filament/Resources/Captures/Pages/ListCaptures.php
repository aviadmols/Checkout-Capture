<?php

namespace App\Filament\Resources\Captures\Pages;

use App\Filament\Resources\Captures\CaptureResource;
use App\Models\Capture;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListCaptures extends ListRecords
{
    protected static string $resource = CaptureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn () => $this->exportCsv()),
        ];
    }

    /** Exports whatever the table currently shows (search + filters applied). */
    private function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery()->with('shop');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Hebrew correctly
            fputcsv($out, ['email', 'first_name', 'last_name', 'phone', 'birthday', 'accepts_marketing', 'accepts_sms', 'source', 'shop', 'cart_total', 'currency', 'items', 'submissions', 'first_seen', 'last_seen']);

            $query->lazyById(500)->each(function (Capture $c) use ($out) {
                fputcsv($out, [
                    $c->email,
                    $c->first_name,
                    $c->last_name,
                    $c->phone,
                    $c->birthday?->toDateString(),
                    $c->accepts_marketing ? 'yes' : 'no',
                    $c->accepts_sms ? 'yes' : 'no',
                    $c->source,
                    $c->shop?->name,
                    $c->cart_total_cents !== null ? number_format($c->cart_total_cents / 100, 2, '.', '') : null,
                    $c->currency,
                    $c->item_count,
                    $c->submissions_count,
                    $c->first_captured_at?->toDateTimeString(),
                    $c->last_captured_at?->toDateTimeString(),
                ]);
            });

            fclose($out);
        }, 'captures-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
