<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Pricing by monthly activations — an activation is one cart the widget attached an
 * email to before sending the shopper to checkout. The plan follows usage: a shop
 * pays the tier its activation count reached that month.
 */
final class UsagePlan
{
    /** `up_to` is inclusive; null means no upper limit. Prices in USD per month. */
    public const TIERS = [
        ['name' => 'Free', 'up_to' => 50, 'price' => 0],
        ['name' => 'Growth', 'up_to' => 1000, 'price' => 14],
        ['name' => 'Pro', 'up_to' => null, 'price' => 25],
    ];

    /** @return array{name: string, up_to: ?int, price: int} */
    public static function forActivations(int $count): array
    {
        foreach (self::TIERS as $tier) {
            if ($tier['up_to'] === null || $count <= $tier['up_to']) {
                return $tier;
            }
        }

        return self::TIERS[array_key_last(self::TIERS)];
    }

    /** Billing period key, e.g. "2026-10". Calendar months in the app timezone. */
    public static function period(?CarbonInterface $at = null): string
    {
        return ($at ?? now())->format('Y-m');
    }

    public static function label(int $count): string
    {
        $tier = self::forActivations($count);

        return $tier['price'] ? "{$tier['name']} · \${$tier['price']}" : $tier['name'];
    }
}
