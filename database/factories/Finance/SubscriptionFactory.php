<?php

namespace Database\Factories\Finance;

use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionInterval;
use App\Models\Finance\SubscriptionSource;
use App\Models\Finance\SubscriptionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'description_match' => mb_strtolower($name),
            'amount' => fake()->numberBetween(300, 3000),
            'interval' => SubscriptionInterval::Monthly,
            'next_date' => today()->addDays(fake()->numberBetween(1, 28))->toDateString(),
            'status' => SubscriptionStatus::Active,
            'source' => SubscriptionSource::Manual,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
    }
}
