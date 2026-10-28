<?php

namespace App\Verification\States;

use App\Models\User;
use App\Verification\Contracts\ScenarioState;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

class TwoFactorEnabled implements ScenarioState
{
    public function __construct(private EnableTwoFactorAuthentication $enableTwoFactorAuthentication) {}

    public function name(): string
    {
        return 'two-factor';
    }

    public function description(): string
    {
        return 'Confirmed TOTP two-factor authentication; prints the secret and recovery codes.';
    }

    public function arrange(User $user): array
    {
        ($this->enableTwoFactorAuthentication)($user);

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return [
            'two_factor_secret' => Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret),
            'recovery_codes' => $user->recoveryCodes(),
        ];
    }
}
