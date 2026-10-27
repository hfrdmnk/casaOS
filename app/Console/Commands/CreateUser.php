<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;

#[Signature('user:create {name : The name of the user} {email : The email address of the user}')]
#[Description('Create a user account (registration is disabled)')]
class CreateUser extends Command implements PromptsForMissingInput
{
    /**
     * Execute the console command.
     */
    public function handle(CreateNewUser $creator): int
    {
        $generatedPassword = $this->input->isInteractive() ? null : Str::password(24);
        $password = $generatedPassword ?? password('Password', required: true);

        try {
            $user = $creator->create([
                'name' => $this->argument('name'),
                'email' => $this->argument('email'),
                'password' => $password,
                'password_confirmation' => $password,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $this->components->info("Created user {$user->email}.");

        if ($generatedPassword !== null) {
            $this->components->twoColumnDetail('Generated password', $generatedPassword);
            $this->components->warn('Change it after logging in.');
        }

        return self::SUCCESS;
    }
}
