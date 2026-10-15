<?php

namespace App\Console\Commands;

use App\Events\CartAbandoned;
use App\Models\Shop;
use App\Models\TrackedCart;
use Illuminate\Console\Command;

class DetectAbandonedCarts extends Command
{
    protected $signature = 'carts:detect-abandoned';

    protected $description = 'Mark idle carts of identified shoppers as abandoned';

    public function handle(): int
    {
        $marked = 0;

        Shop::query()->where('mode', '!=', Shop::MODE_OFF)->each(function (Shop $shop) use (&$marked) {
            $settings = $shop->resolvedSettings();

            if (! $settings['abandoned_carts']) {
                return;
            }

            $idleSince = now()->subMinutes(max(5, (int) $settings['abandon_after_minutes']));

            $shop->trackedCarts()
                ->where('status', TrackedCart::ACTIVE)
                ->where('item_count', '>', 0)
                ->where('last_activity_at', '<=', $idleSince)
                ->each(function (TrackedCart $cart) use (&$marked) {
                    $cart->update(['status' => TrackedCart::ABANDONED, 'abandoned_at' => now()]);
                    CartAbandoned::dispatch($cart);
                    $marked++;
                });
        });

        $this->info("{$marked} cart(s) marked abandoned.");

        return self::SUCCESS;
    }
}
