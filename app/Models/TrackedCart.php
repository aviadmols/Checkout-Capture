<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cart of a shopper whose email we know (from a link, the popup or a store form).
 * Marked abandoned after the shop's idle time unless the shopper heads to checkout.
 */
#[Fillable([
    'shop_id', 'capture_id', 'email', 'source', 'cart_token', 'status',
    'total_cents', 'currency', 'item_count', 'items', 'last_activity_at', 'abandoned_at',
])]
class TrackedCart extends Model
{
    public const ACTIVE = 'active';

    public const ABANDONED = 'abandoned';

    public const CHECKOUT = 'checkout';

    public const EMPTIED = 'emptied';

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_cents' => 'integer',
            'item_count' => 'integer',
            'last_activity_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function capture(): BelongsTo
    {
        return $this->belongsTo(Capture::class);
    }

    /**
     * Shopify cart permalink: rebuilds this cart on any device and goes straight to
     * checkout with the email filled in. This is the link a recovery message sends.
     */
    public function recoveryUrl(): ?string
    {
        $lines = collect($this->items ?? [])
            ->filter(fn (array $item) => ! empty($item['variant_id']) && ($item['quantity'] ?? 0) > 0)
            ->map(fn (array $item) => $item['variant_id'].':'.$item['quantity']);

        if ($lines->isEmpty() || ! $this->shop) {
            return null;
        }

        return 'https://'.$this->shop->storefrontHost().'/cart/'.$lines->implode(',')
            .'?'.http_build_query(['checkout' => ['email' => $this->email]]);
    }
}
