<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * The theme app extension ships its own copy of the widget (served from Shopify's CDN).
 * public/js/checkout-capture.js stays the source of truth; run this before `shopify app deploy`.
 */
class SyncShopifyExtension extends Command
{
    public const SOURCE = 'public/js/checkout-capture.js';

    public const TARGET = 'extensions/checkout-capture/assets/checkout-capture.js';

    protected $signature = 'shopify:sync-extension';

    protected $description = 'Copy the storefront widget into the theme app extension';

    public function handle(): int
    {
        copy(base_path(self::SOURCE), base_path(self::TARGET));
        $this->info('Widget copied to '.self::TARGET);

        return self::SUCCESS;
    }
}
