<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Shopify usage record created when a shop's activations cross a pricing tier in a billing cycle. */
#[Fillable(['shop_id', 'period', 'tier', 'amount', 'shopify_usage_record_id', 'status', 'error'])]
class UsageCharge extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
