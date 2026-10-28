<?php

namespace App\Console\Commands\Verify;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Help;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\URL;

#[Signature('verify:login {scenario : Scenario id printed by verify:scenario} {--path=/ : App path to land on after logging in} {--minutes=30 : Minutes until the URL expires} {--base= : Base URL to prefix (defaults to APP_URL; use a portal URL when the browser reaches the app through one)}')]
#[Description('Print a temporary signed URL that logs the browser in as a scenario user')]
#[Help(<<<'HELP'
Prints JSON with a signed, expiring URL. Opening it logs the browser in as the scenario user
(skipping password and two-factor) and redirects to --path. The signature is relative, so the same
path works behind any host; --base only changes the printed prefix.

  php artisan verify:login ab12cd34
  php artisan verify:login ab12cd34 --path=/settings/profile
  agent-browser open "$(php artisan verify:login ab12cd34 | jq -r .url)"

Only scenario users (verify-<id>@casaos.test) can log in this way; any other user gets 404.
To prove the real login form instead, use the scenario's email and password with /login.
HELP)]
class Login extends VerifyCommand
{
    public function verify(): int
    {
        $user = $this->scenarioUser((string) $this->argument('scenario'));

        if ($user === null) {
            return self::FAILURE;
        }

        $path = (string) $this->option('path');
        $signedPath = URL::temporarySignedRoute(
            'verify.login',
            now()->addMinutes((int) $this->option('minutes')),
            ['user' => $user->id, 'path' => $path],
            absolute: false,
        );

        $this->json([
            'scenario' => $this->argument('scenario'),
            'email' => $user->email,
            'path' => $path,
            'url' => rtrim((string) ($this->option('base') ?: config('app.url')), '/').$signedPath,
        ]);

        return self::SUCCESS;
    }
}
