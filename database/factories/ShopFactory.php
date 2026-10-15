<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    public function definition(): array
    {
        $handle = fake()->unique()->slug(2);

        return [
            'name' => fake()->company(),
            'shop_domain' => "{$handle}.myshopify.com",
            'allowed_origins' => ["{$handle}.co.il", "www.{$handle}.co.il"],
            'storefront_access_token' => null,
            'mode' => Shop::MODE_POPUP,
            'settings' => [],
        ];
    }

    public function off(): static
    {
        return $this->state(['mode' => Shop::MODE_OFF]);
    }

    public function urlOnly(): static
    {
        return $this->state(['mode' => Shop::MODE_URL]);
    }
}
