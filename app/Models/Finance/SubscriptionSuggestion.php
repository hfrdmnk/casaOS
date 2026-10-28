<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Regular charges that detection found, waiting for the user to confirm them as a subscription or
 * dismiss them. A dismissed suggestion keeps its `match_key` and interval from being suggested
 * again. `amount` is the median charge as positive Rappen.
 *
 * @property int $id
 * @property int $user_id
 * @property string $match_key `payee:<id>` or `description:<normalized description>`
 * @property string $name
 * @property int|null $payee_id
 * @property string|null $description_match
 * @property int|null $account_id
 * @property int $amount
 * @property SubscriptionInterval $interval
 * @property string $next_date Y-m-d
 * @property list<int> $transaction_ids
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payee|null $payee
 * @property-read Account|null $account
 */
#[Fillable(['match_key', 'name', 'payee_id', 'description_match', 'account_id', 'amount', 'interval', 'next_date', 'transaction_ids', 'dismissed_at'])]
class SubscriptionSuggestion extends Model
{
    use BelongsToUser;

    /**
     * @return BelongsTo<Payee, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The charges detection grouped, oldest first (rows deleted since are left out).
     *
     * @return Collection<int, Transaction>
     */
    public function charges(): Collection
    {
        return Transaction::query()->where('user_id', $this->user_id)->whereIn('id', $this->transaction_ids)
            ->with('account')->orderBy('date')->orderBy('time')->orderBy('id')->get();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'interval' => SubscriptionInterval::class,
            'transaction_ids' => 'array',
            'dismissed_at' => 'datetime',
        ];
    }
}
