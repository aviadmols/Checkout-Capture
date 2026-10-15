<?php

namespace Database\Factories;

use App\Models\Capture;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Capture>
 */
class CaptureFactory extends Factory
{
    public function definition(): array
    {
        $capturedAt = fake()->dateTimeBetween('-30 days');

        return [
            'shop_id' => Shop::factory(),
            'email' => fake()->unique()->safeEmail(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => null,
            'accepts_marketing' => fake()->boolean(),
            'cart_total_cents' => fake()->numberBetween(5_000, 90_000),
            'currency' => 'ILS',
            'item_count' => fake()->numberBetween(1, 5),
            'submissions_count' => 1,
            'first_captured_at' => $capturedAt,
            'last_captured_at' => $capturedAt,
        ];
    }
}
