<?php

namespace App\Actions\Finance;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gives a user the default finance categories. Runs when a user is created; existing names are
 * left alone, so it is safe to run again.
 */
final class SeedDefaultCategories
{
    public const NAMES = [
        'Groceries',
        'Dining',
        'Transport',
        'Software & Subscriptions',
        'Books',
        'Shopping',
        'Housing',
        'Utilities',
        'Health',
        'Insurance',
        'Income',
        'Other',
    ];

    public function __invoke(User $user): void
    {
        $now = now();

        DB::table('categories')->insertOrIgnore(array_map(fn (string $name): array => [
            'user_id' => $user->id,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::NAMES));
    }
}
