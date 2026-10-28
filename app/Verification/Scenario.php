<?php

namespace App\Verification;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Naming rules for verification scenarios.
 *
 * A scenario is one user whose email is `verify-<id>@casaos.test`. Recipes that change the
 * email keep the scenario by plus-addressing it: `verify-<id>+new@casaos.test`.
 */
class Scenario
{
    public const ID_LENGTH = 8;

    public static function newId(): string
    {
        return Str::lower(Str::random(self::ID_LENGTH));
    }

    public static function email(string $id): string
    {
        return "verify-{$id}@casaos.test";
    }

    public static function idFromEmail(string $email): ?string
    {
        return preg_match('/^verify-([a-z0-9]{'.self::ID_LENGTH.'})(\+[^@]*)?@casaos\.test$/', Str::lower($email), $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * Every scenario user, and no other user.
     *
     * @return Builder<User>
     */
    public static function users(): Builder
    {
        return User::query()->where('email', 'like', 'verify-%@casaos.test');
    }

    public static function user(string $id): ?User
    {
        return User::query()
            ->where(fn (Builder $query) => $query
                ->where('email', self::email($id))
                ->orWhere('email', 'like', "verify-{$id}+%@casaos.test"))
            ->first();
    }

    /**
     * Where mail sent to the scenario's addresses is captured.
     */
    public static function mailbox(string $id): string
    {
        return storage_path("verify/mail/{$id}");
    }
}
