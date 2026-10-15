<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Shopify order placed by a shopper the app captured (from the orders/create webhook). */
#[Fillable(['shop_id', 'capture_id', 'shopify_order_id', 'name', 'email', 'matched_by', 'total_cents', 'currency', 'ordered_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'shopify_order_id' => 'integer',
            'total_cents' => 'integer',
            'ordered_at' => 'datetime',
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
}
