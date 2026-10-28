<?php

namespace Database\Factories\Finance;

use App\Models\Finance\Rule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rules made by the factory skip validation; tests that need a category or payee action pass
 * their own `actions`.
 *
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
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
            'name' => null,
            'position' => fn (array $attributes): int => Rule::query()->where('user_id', $attributes['user_id'])->max('position') + 1,
            'enabled' => true,
            'conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => fake()->word()]],
            'actions' => [],
        ];
    }
}
