<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * A row owned by one user. Route model binding only resolves the signed-in user's own rows, so
 * another user's id is a 404, never a 403: the response does not reveal that the row exists.
 *
 * @property int $user_id
 * @property-read User $user
 *
 * @mixin Model
 */
trait BelongsToUser
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->resolveRouteBindingQuery($this->newQuery(), $value, $field)
            ->where($this->qualifyColumn('user_id'), Auth::id())
            ->firstOrFail();
    }

    /**
     * Rows owned by the user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $user->id);
    }
}
