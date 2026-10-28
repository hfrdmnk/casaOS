<?php

namespace App\Verification\Finance;

use App\Models\User;
use App\Verification\Contracts\ScenarioState;

/**
 * A user with the default categories and no accounts, mappings or imports. Every new user is in
 * this state; naming it makes the recipe explicit.
 */
class FinanceEmpty implements ScenarioState
{
    public function name(): string
    {
        return 'finance-empty';
    }

    public function description(): string
    {
        return 'Default finance categories, no accounts, mappings or imports (the Import page opens the mapper).';
    }

    public function arrange(User $user): array
    {
        return ['categories' => $user->categories()->count()];
    }
}
