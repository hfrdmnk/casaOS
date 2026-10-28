<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\Finance\BalanceSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The account's closing balance at the end of `date`, in signed Rappen.
 *
 * @property int $id
 * @property int $user_id
 * @property int $account_id
 * @property int|null $import_batch_id
 * @property string $date Y-m-d
 * @property int $balance
 * @property SnapshotSource $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
#[Fillable(['account_id', 'import_batch_id', 'date', 'balance', 'source'])]
class BalanceSnapshot extends Model
{
    /** @use HasFactory<BalanceSnapshotFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'source' => SnapshotSource::class,
        ];
    }
}
