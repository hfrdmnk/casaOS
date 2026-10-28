<?php

namespace App\Verification\Contracts;

use App\Models\User;

/**
 * A named state that `verify:scenario` arranges on top of a fresh scenario user.
 *
 * Register implementations with the container tag in a service provider:
 * `$this->app->tag([MyState::class], ScenarioState::TAG);`
 */
interface ScenarioState
{
    public const TAG = 'verify.scenario-states';

    /**
     * The name passed to `verify:scenario`, e.g. `two-factor` or `finance-budget`.
     */
    public function name(): string;

    /**
     * One line telling an agent what the state arranges.
     */
    public function description(): string;

    /**
     * Arrange the state for the scenario user.
     *
     * @return array<string, mixed> Values the agent needs to drive the scenario (codes, record ids).
     */
    public function arrange(User $user): array;
}
