<?php

namespace App\Console\Commands\Verify;

use App\Models\User;
use App\Verification\Contracts\ScenarioState;
use App\Verification\Scenario;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('verify:scenario {states?* : Named states to arrange on the new user (see --list)} {--name= : Display name for the scenario user} {--list : List the available states as JSON and create nothing}')]
#[Description('Create an isolated scenario user, optionally in named states, and print it as JSON')]
#[Help(<<<'HELP'
Creates one user whose email is verify-<id>@casaos.test, with a generated password, then arranges each
named state on it in one transaction. Prints JSON with the scenario id, credentials, and the values
each state returns (e.g. the two-factor secret and recovery codes).

  php artisan verify:scenario
  php artisan verify:scenario two-factor
  php artisan verify:scenario --list

Next: `php artisan verify:login <scenario>` to get a login URL, `php artisan verify:inspect <scenario>`
to read state, `php artisan verify:cleanup <scenario>` when done.

Modules add states by implementing App\Verification\Contracts\ScenarioState and tagging the class
with ScenarioState::TAG in a service provider.
HELP)]
class CreateScenario extends VerifyCommand
{
    public function verify(): int
    {
        /** @var Collection<string, ScenarioState> $available */
        $available = collect($this->laravel->tagged(ScenarioState::TAG))
            ->keyBy(fn (ScenarioState $state): string => $state->name());

        if ($this->option('list')) {
            $this->json(['states' => $available->map(fn (ScenarioState $state): string => $state->description())->all()]);

            return self::SUCCESS;
        }

        /** @var list<string> $requested */
        $requested = array_values(array_unique($this->argument('states')));
        $unknown = array_diff($requested, $available->keys()->all());

        if ($unknown !== []) {
            $this->components->error(sprintf(
                'Unknown state [%s]. Available states: %s. Nothing was created.',
                implode(', ', $unknown),
                $available->keys()->implode(', ') ?: 'none',
            ));

            return self::FAILURE;
        }

        $id = Scenario::newId();
        $password = Str::password(20, symbols: false);

        [$user, $arranged] = DB::transaction(function () use ($id, $password, $requested, $available): array {
            $user = User::create([
                'name' => $this->option('name') ?: "Verify {$id}",
                'email' => Scenario::email($id),
                'password' => $password,
            ]);

            $arranged = collect($requested)
                ->mapWithKeys(fn (string $name): array => [$name => $available[$name]->arrange($user)])
                ->all();

            return [$user, $arranged];
        });

        $this->json([
            'scenario' => $id,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'password' => $password,
            'states' => (object) $arranged,
            'next' => [
                'login' => "php artisan verify:login {$id}",
                'inspect' => "php artisan verify:inspect {$id}",
                'cleanup' => "php artisan verify:cleanup {$id}",
            ],
        ]);

        return self::SUCCESS;
    }
}
