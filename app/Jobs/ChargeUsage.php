<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\Shopify\Billing;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs after the activation response is sent, so the storefront never waits on Shopify billing. */
class ChargeUsage
{
    use Dispatchable;

    public function __construct(public int $shopId) {}

    public function handle(Billing $billing): void
    {
        $shop = Shop::find($this->shopId);

        if (! $shop) {
            return;
        }

        try {
            $billing->chargeForUsage($shop);
        } catch (Throwable $e) {
            Log::warning("Usage charge failed for shop {$shop->shop_domain}: {$e->getMessage()}");
        }
    }
}
