<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\Finance\ImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One import of one file. `summary` holds the counts per account and the skipped rows.
 *
 * @property int $id
 * @property int $user_id
 * @property string $filename
 * @property string $signature
 * @property array{
 *     created: int,
 *     updated: int,
 *     skipped_duplicate: int,
 *     skipped_currency: int,
 *     removed_reverted: int,
 *     skipped_reverted: int,
 *     unmapped_rows: int,
 *     accounts: list<array{account_id: int, account: string, mapping: string, created: int, updated: int, skipped_duplicate: int, skipped_currency: int, removed_reverted: int, skipped_reverted: int, snapshot: array{date: string, balance: int}|null}>,
 *     currency_rows: list<array{line: int, account: string, date: string, description: string, amount: string, currency: string}>
 * } $summary
 * @property Carbon|null $undone_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['filename', 'signature', 'summary', 'undone_at'])]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return HasMany<ImportBatchChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(ImportBatchChange::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return list<int>
     */
    public function accountIds(): array
    {
        return array_values(array_unique(array_column($this->summary['accounts'], 'account_id')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'undone_at' => 'datetime',
        ];
    }
}
