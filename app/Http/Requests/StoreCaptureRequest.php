<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'name' => $this->filled('name') ? Str::squish((string) $this->input('name')) : null,
            'phone' => $this->filled('phone') ? preg_replace('/[^\d+]/', '', (string) $this->input('phone')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'min:7', 'max:20'],
            'accepts_marketing' => ['nullable', 'boolean'],
            // Honeypot — real visitors never see this field.
            'website' => ['nullable', 'string', 'max:255'],

            'locale' => ['nullable', 'string', 'max:16'],
            'page_url' => ['nullable', 'string', 'max:2048'],

            'cart' => ['nullable', 'array'],
            'cart.token' => ['nullable', 'string', 'max:255'],
            'cart.total_price' => ['nullable', 'integer', 'min:0'],
            'cart.currency' => ['nullable', 'string', 'size:3'],
            'cart.item_count' => ['nullable', 'integer', 'min:0'],
            'cart.items' => ['nullable', 'array', 'max:100'],
            'cart.items.*.variant_id' => ['nullable', 'integer'],
            'cart.items.*.product_title' => ['nullable', 'string', 'max:255'],
            'cart.items.*.variant_title' => ['nullable', 'string', 'max:255'],
            'cart.items.*.quantity' => ['nullable', 'integer', 'min:0'],
            'cart.items.*.price' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function isBot(): bool
    {
        return $this->filled('website');
    }
}
