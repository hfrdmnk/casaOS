<?php

namespace App\Verification\Contracts;

use App\Models\User;

/**
 * Contributes one top-level key to the JSON printed by `verify:inspect`.
 *
 * Register implementations with the container tag in a service provider:
 * `$this->app->tag([MyInspector::class], Inspector::TAG);`
 */
interface Inspector
{
    public const TAG = 'verify.inspectors';

    /**
     * The top-level key in the inspector output, e.g. `account` or `finance`.
     */
    public function key(): string;

    /**
     * Read the scenario user's state.
     *
     * @return array<string, mixed>
     */
    public function inspect(User $user): array;
}
