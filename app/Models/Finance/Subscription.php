<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\Finance\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring payment. Its charges match by payee when it has one, else by `description_match`
 * (contained in the normalized description, see ManageSubscriptions::normalize()), and only in
 * `account_id` when set. `amount` is the expected charge as positive Rappen.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property int|null $payee_id
 * @property string|null $description_match
 * @property int|null $account_id
 * @property int $amount
 * @property SubscriptionInterval $interval
 * @property string $next_date Y-m-d
 * @property SubscriptionStatus $status
 * @property SubscriptionSource $source
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payee|null $payee
 * @property-read Account|null $account
 */
#[Fillable(['name', 'payee_id', 'description_match', 'account_id', 'amount', 'interval', 'next_date', 'status', 'source', 'cancelled_at'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToUser, HasFactory;

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
     * The linked charges: the payment history.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active);
    }

    /**
     * The average cost per month in Rappen.
     */
    public function monthlyAmount(): int
    {
        return $this->interval->monthly($this->amount);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'interval' => SubscriptionInterval::class,
            'status' => SubscriptionStatus::class,
            'source' => SubscriptionSource::class,
            'cancelled_at' => 'datetime',
        ];
    }
}
