<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shop_id', 'capture_id', 'email', 'cart_token', 'source', 'period', 'dedupe_key'])]
class Activation extends Model
{
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function capture(): BelongsTo
    {
        return $this->belongsTo(Capture::class);
    }
}
