<?php

namespace App\Http\Requests\Shopify;

use App\Models\Shop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Settings saved from the embedded app (the merchant's own screen inside Shopify admin). */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $text = ['nullable', 'string', 'max:300'];

        return [
            'mode' => ['required', Rule::in(Shop::MODES)],
            'webhook_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],

            'settings' => ['required', 'array'],
            'settings.design' => ['required', Rule::in(Shop::DESIGNS)],
            'settings.direction' => ['required', Rule::in(['ltr', 'rtl'])],
            'settings.image_url' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'settings.accent_color' => ['nullable', 'regex:/^#[0-9a-f]{3,8}$/i'],
            'settings.url_param' => ['nullable', 'regex:/^[A-Za-z0-9_\-\[\]]+$/', 'max:40'],
            'settings.abandon_after_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'settings.extra_selectors' => ['nullable', 'string', 'max:500'],
            'settings.subtitle' => ['nullable', 'string', 'max:600'],
            'settings.privacy_note' => ['nullable', 'string', 'max:600'],
            ...collect(['eyebrow', 'title', 'submit_label', 'skip_label', 'close_label', 'error_email', 'error_required'])
                ->mapWithKeys(fn (string $key) => ["settings.{$key}" => $text])
                ->all(),
            ...collect(['allow_skip', 'url_capture', 'form_capture', 'abandoned_carts', 'identify_marketing_apps'])
                ->mapWithKeys(fn (string $key) => ["settings.{$key}" => ['required', 'boolean']])
                ->all(),

            'settings.fields' => ['required', 'array', 'max:'.count(Shop::FIELDS)],
            'settings.fields.*.key' => ['required', 'distinct', Rule::in(array_keys(Shop::FIELDS))],
            'settings.fields.*.enabled' => ['required', 'boolean'],
            'settings.fields.*.required' => ['required', 'boolean'],
            'settings.fields.*.label' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'webhook_url.starts_with' => 'The webhook URL must start with https://.',
            'settings.image_url.starts_with' => 'The image URL must start with https://.',
            'settings.abandon_after_minutes.min' => 'Carts can be marked abandoned after 5 minutes at the earliest.',
        ];
    }
}
