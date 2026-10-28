<?php

namespace Database\Factories\Finance;

use App\Models\Finance\Account;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'date' => fake()->dateTimeBetween('-2 months')->format('Y-m-d'),
            'description' => fake()->company(),
            'amount' => -fake()->numberBetween(100, 20000),
            'fee' => 0,
            'currency' => 'CHF',
            'status' => TransactionStatus::Completed,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => TransactionStatus::Pending]);
    }
}
