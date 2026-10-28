<?php

namespace Database\Factories\Finance;

use App\Models\Finance\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
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
            'filename' => 'export.csv',
            'signature' => hash('sha256', 'export'),
            'summary' => [
                'created' => 0, 'updated' => 0, 'skipped_duplicate' => 0, 'skipped_currency' => 0,
                'removed_reverted' => 0, 'skipped_reverted' => 0, 'unmapped_rows' => 0,
                'accounts' => [], 'currency_rows' => [],
            ],
        ];
    }
}
