<?php

namespace App\Events;

use App\Models\TrackedCart;
use Illuminate\Foundation\Events\Dispatchable;

/** Fired once when an identified shopper's cart passes the shop's idle time. Recovery delivery hooks in here. */
class CartAbandoned
{
    use Dispatchable;

    public function __construct(public TrackedCart $cart) {}
}
