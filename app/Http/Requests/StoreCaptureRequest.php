<?php

namespace App\Http\Requests;

use App\Models\Shop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $squish = fn (string $key) => $this->filled($key) ? Str::squish((string) $this->input($key)) : null;

        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
            'name' => $squish('name'),
            'first_name' => $squish('first_name'),
            'last_name' => $squish('last_name'),
            'phone' => $this->filled('phone') ? preg_replace('/[^\d+]/', '', (string) $this->input('phone')) : null,
            'birthday' => $this->filled('birthday') ? $this->input('birthday') : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'max:120'],
            'first_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'min:7', 'max:20'],
            'birthday' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'accepts_marketing' => ['nullable', 'boolean'],
            'accepts_sms' => ['nullable', 'boolean'],
            'source' => ['nullable', Rule::in(Shop::SOURCES)],
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
