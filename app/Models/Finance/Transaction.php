<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\Finance\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money is signed Rappen. `amount` is the net effect on the account balance (the file's amount
 * minus its fee); `fee` keeps the fee itself as a positive number.
 *
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $category_id
 * @property int|null $payee_id
 * @property FieldSource|null $category_source who set `category_id`
 * @property FieldSource|null $payee_source who set `payee_id`
 * @property bool $is_transfer neither income nor spending; carries no category
 * @property TransferSource|null $transfer_source who decided `is_transfer`
 * @property int|null $transfer_counterpart_id the other side of the pair, which points back here
 * @property int|null $subscription_id the subscription this charge belongs to
 * @property SubscriptionLinkSource|null $subscription_source who decided `subscription_id`
 * @property int|null $import_batch_id
 * @property string $date Y-m-d
 * @property string|null $time
 * @property string $description
 * @property int $amount
 * @property int $fee
 * @property int|null $balance_after
 * @property string $currency
 * @property TransactionStatus $status
 * @property string|null $fingerprint
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Category|null $category
 * @property-read Payee|null $payee
 * @property-read Transaction|null $transferCounterpart
 * @property-read Subscription|null $subscription
 */
#[Fillable(['account_id', 'category_id', 'payee_id', 'category_source', 'payee_source', 'is_transfer', 'transfer_source', 'transfer_counterpart_id', 'subscription_id', 'subscription_source', 'import_batch_id', 'date', 'time', 'description', 'amount', 'fee', 'balance_after', 'currency', 'status', 'fingerprint'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Payee, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    /**
     * The other side of a transfer pair.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transferCounterpart(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transfer_counterpart_id');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Transactions that count as income or spending: everything but transfers. Totals of income
     * and spending must go through this scope; balances must not (each side of a transfer moves
     * its own account's balance).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCountsAsIncomeOrSpending(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_transfer'), false);
    }

    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'balance_after' => 'integer',
            'status' => TransactionStatus::class,
            'category_source' => FieldSource::class,
            'payee_source' => FieldSource::class,
            'is_transfer' => 'boolean',
            'transfer_source' => TransferSource::class,
            'subscription_source' => SubscriptionLinkSource::class,
        ];
    }
}
