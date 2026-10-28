<?php

namespace Database\Factories\Finance;

use App\Models\Finance\Account;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\SnapshotSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BalanceSnapshot>
 */
class BalanceSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'user_id' => fn (array $attributes): int => Account::query()->whereKey($attributes['account_id'])->firstOrFail()->user_id,
            'date' => fake()->date(),
            'balance' => fake()->numberBetween(0, 1000000),
            'source' => SnapshotSource::Manual,
        ];
    }
}
