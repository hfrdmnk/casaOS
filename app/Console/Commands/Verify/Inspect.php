<?php

namespace App\Console\Commands\Verify;

use App\Verification\Contracts\Inspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Collection;

#[Signature('verify:inspect {scenario : Scenario id printed by verify:scenario} {--only=* : Limit output to these inspector keys (repeatable)}')]
#[Description('Print a scenario user\'s state as JSON, one key per registered inspector')]
#[Help(<<<'HELP'
Reads the scenario user's state from the database and prints JSON. Each registered inspector adds
one top-level key; today that is `account` (profile, sessions, pending password reset, passkeys,
two-factor status and the current TOTP code).

  php artisan verify:inspect ab12cd34 > inspect-before.json
  php artisan verify:inspect ab12cd34 --only=account

Save it before and after the action you drive and diff the two.

Modules add keys by implementing App\Verification\Contracts\Inspector and tagging the class with
Inspector::TAG in a service provider.
HELP)]
class Inspect extends VerifyCommand
{
    public function verify(): int
    {
        $scenario = (string) $this->argument('scenario');
        $user = $this->scenarioUser($scenario);

        if ($user === null) {
            return self::FAILURE;
        }

        /** @var Collection<string, Inspector> $inspectors */
        $inspectors = collect($this->laravel->tagged(Inspector::TAG))
            ->keyBy(fn (Inspector $inspector): string => $inspector->key());

        /** @var list<string> $only */
        $only = $this->option('only');
        $unknown = array_diff($only, $inspectors->keys()->all());

        if ($unknown !== []) {
            $this->components->error(sprintf(
                'Unknown inspector [%s]. Available inspectors: %s.',
                implode(', ', $unknown),
                $inspectors->keys()->implode(', '),
            ));

            return self::FAILURE;
        }

        $this->json([
            'scenario' => $scenario,
            'inspected_at' => now()->toIso8601String(),
            ...$inspectors
                ->when($only !== [], fn (Collection $all): Collection => $all->only($only))
                ->map(fn (Inspector $inspector): array => $inspector->inspect($user))
                ->all(),
        ]);

        return self::SUCCESS;
    }
}
