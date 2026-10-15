<?php

namespace App\Listeners;

use App\Events\CartAbandoned;
use App\Services\AbandonedCartWebhook;

class SendAbandonedCartWebhook
{
    public function __construct(private AbandonedCartWebhook $webhook) {}

    public function handle(CartAbandoned $event): void
    {
        $this->webhook->deliver($event->cart);
    }
}
