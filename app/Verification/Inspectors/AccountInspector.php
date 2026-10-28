<?php

namespace App\Verification\Inspectors;

use App\Models\User;
use App\Verification\Contracts\Inspector;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

class AccountInspector implements Inspector
{
    public function __construct(private Google2FA $google2fa) {}

    public function key(): string
    {
        return 'account';
    }

    public function inspect(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
            'password_reset_pending' => DB::table('password_reset_tokens')->where('email', $user->email)->exists(),
            'passkeys' => $user->passkeys()->pluck('name')->all(),
            'api_tokens' => $user->tokens()->orderBy('id')->get()->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ])->all(),
            'two_factor' => [
                'enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => $user->two_factor_recovery_codes === null ? 0 : count($user->recoveryCodes()),
                'current_code' => $user->two_factor_secret !== null
                    ? $this->google2fa->getCurrentOtp(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret))
                    : null,
            ],
        ];
    }
}
