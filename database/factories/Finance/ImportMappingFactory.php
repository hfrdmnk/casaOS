<?php

namespace Database\Factories\Finance;

use App\Models\Finance\Account;
use App\Models\Finance\AmountMode;
use App\Models\Finance\ImportMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A mapping for a plain `Date,Description,Amount` file.
 *
 * @extends Factory<ImportMapping>
 */
class ImportMappingFactory extends Factory
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
            'name' => 'Simple export',
            'encoding' => 'UTF-8',
            'delimiter' => ',',
            'skip_lines' => 0,
            'header' => ['Date', 'Description', 'Amount'],
            'date_column' => 0,
            'date_format' => 'Y-m-d',
            'description_column' => 1,
            'amount_mode' => AmountMode::Signed,
            'amount_column' => 2,
            'decimal_separator' => '.',
        ];
    }
}
