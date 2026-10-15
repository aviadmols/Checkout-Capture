<?php

namespace App\Listeners;

use App\Events\CartAbandoned;
use App\Services\Shopify\FlowTrigger;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Shops installed through Shopify also get the "Cart abandoned" Flow trigger. */
class FireShopifyFlowTrigger
{
    public function __construct(private FlowTrigger $flow) {}

    public function handle(CartAbandoned $event): void
    {
        if (! $event->cart->shop?->isInstalled()) {
            return;
        }

        try {
            $this->flow->cartAbandoned($event->cart);
        } catch (Throwable $e) {
            // Never let a Flow failure stop the webhook or the next cart.
            Log::warning("Flow trigger failed for cart {$event->cart->id}: {$e->getMessage()}");
        }
    }
}
