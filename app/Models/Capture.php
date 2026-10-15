<?php

namespace App\Models;

use Database\Factories\CaptureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shop_id', 'email', 'first_name', 'last_name', 'phone', 'accepts_marketing',
    'cart_token', 'cart_total_cents', 'currency', 'item_count', 'cart_items',
    'locale', 'page_url', 'ip_address', 'user_agent',
    'submissions_count', 'first_captured_at', 'last_captured_at',
])]
class Capture extends Model
{
    /** @use HasFactory<CaptureFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'accepts_marketing' => 'boolean',
            'cart_items' => 'array',
            'cart_total_cents' => 'integer',
            'item_count' => 'integer',
            'submissions_count' => 'integer',
            'first_captured_at' => 'datetime',
            'last_captured_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function fullName(): ?string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: null;
    }
}
