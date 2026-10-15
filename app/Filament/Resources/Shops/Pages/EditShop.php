<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Filament\Resources\Shops\ShopResource;
use App\Models\Shop;
use App\Services\AbandonedCartWebhook;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * @property Shop $record
 */
class EditShop extends EditRecord
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview popup')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn () => route('shops.preview', $this->record), shouldOpenInNewTab: true),
            Action::make('testWebhook')
                ->label('Send test webhook')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->visible(fn () => filled($this->record->webhook_url))
                ->action(function (AbandonedCartWebhook $webhook) {
                    $result = $webhook->sendTest($this->record);

                    Notification::make()
                        ->title($result['ok'] ? 'Test webhook delivered' : 'Test webhook failed')
                        ->body($result['ok'] ? "HTTP {$result['status']}" : ($result['status'] ? "HTTP {$result['status']}: " : '').$result['error'])
                        ->status($result['ok'] ? 'success' : 'danger')
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }

    /** Show every available field, including ones added after this shop was last saved. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Filament applies ->default() only on create; toggles and pickers would otherwise
        // show empty/off for keys this shop never saved, and saving would flip them.
        foreach (['design', 'direction', 'accent_color', 'url_capture', 'form_capture', 'abandoned_carts', 'identify_marketing_apps', 'allow_skip'] as $key) {
            $data['settings'][$key] ??= Shop::DEFAULT_SETTINGS[$key];
        }

        $data['settings']['fields'] = array_map(fn (array $field) => [
            'key' => $field['key'],
            'enabled' => $field['enabled'],
            'required' => $field['required'],
            // Keep the default label as a placeholder rather than saving a copy of it.
            'label' => $field['label'] === Shop::FIELDS[$field['key']]['label'] ? null : $field['label'],
        ], $this->record->resolvedFields());

        return $data;
    }

    /** Hidden inputs (e.g. the Popup tab in link-only mode) aren't submitted — keep their saved values. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['settings'] = array_merge($this->record->settings ?? [], $data['settings'] ?? []);

        return $data;
    }
}
