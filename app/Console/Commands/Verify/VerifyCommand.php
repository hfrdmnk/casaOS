<?php

namespace App\Console\Commands\Verify;

use App\Models\User;
use App\Verification\Scenario;
use Illuminate\Console\Command;

/**
 * Base for the `verify:*` levers: refuses to run outside local and testing, then
 * calls the subclass's `verify()` method with its dependencies injected.
 */
abstract class VerifyCommand extends Command
{
    public function handle(): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->components->error(sprintf(
                'verify:* commands only run when APP_ENV is local or testing. This app runs as [%s].',
                $this->laravel->environment(),
            ));

            return self::FAILURE;
        }

        /** @var callable $verify */
        $verify = [$this, 'verify'];

        return (int) $this->laravel->call($verify);
    }

    /**
     * Resolve a scenario's user, or explain how to find a valid scenario id.
     */
    protected function scenarioUser(string $id): ?User
    {
        $user = Scenario::user($id);

        if ($user === null) {
            $this->components->error("No scenario user for [{$id}]. Create one with `php artisan verify:scenario`; scenario ids are printed as `scenario` in its JSON.");
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): void
    {
        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
