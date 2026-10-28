<?php

namespace Database\Factories\Finance;

use App\Models\Finance\Account;
use App\Models\Finance\AccountType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company().' Checking',
            'type' => AccountType::Checking,
            'currency' => 'CHF',
        ];
    }

    public function type(AccountType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
