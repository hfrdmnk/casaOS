<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\Finance\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property AccountType $type
 * @property string $currency
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'currency', 'archived_at'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<BalanceSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(BalanceSnapshot::class);
    }

    /**
     * @return HasMany<ImportMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(ImportMapping::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The balance at the end of a day (Y-m-d): the nearest snapshot plus or minus the completed
     * transactions between it and the day. Without snapshots the account starts at zero. Pending
     * transactions are not part of the balance.
     */
    public function balanceOn(string $date): int
    {
        $day = Carbon::parse($date);

        $nearest = $this->snapshots()->get()
            ->sortBy([
                fn (BalanceSnapshot $a, BalanceSnapshot $b): int => abs((int) Carbon::parse($a->date)->diffInDays($day)) <=> abs((int) Carbon::parse($b->date)->diffInDays($day)),
                fn (BalanceSnapshot $a, BalanceSnapshot $b): int => $a->date <=> $b->date,
            ])
            ->first();

        if ($nearest === null) {
            return $this->completedTotal(null, $date);
        }

        return $nearest->date <= $date
            ? $nearest->balance + $this->completedTotal($nearest->date, $date)
            : $nearest->balance - $this->completedTotal($date, $nearest->date);
    }

    /**
     * Every snapshot, oldest first, with the balance computed from the snapshot before it and the
     * transactions in between. A non-zero difference means the two disagree.
     *
     * @return list<array{snapshot: BalanceSnapshot, expected: int|null, difference: int|null}>
     */
    public function reconciliation(): array
    {
        $previous = null;
        $rows = [];

        foreach ($this->snapshots()->orderBy('date')->get() as $snapshot) {
            $expected = $previous === null ? null : $previous->balance + $this->completedTotal($previous->date, $snapshot->date);
            $rows[] = [
                'snapshot' => $snapshot,
                'expected' => $expected,
                'difference' => $expected === null ? null : $snapshot->balance - $expected,
            ];
            $previous = $snapshot;
        }

        return $rows;
    }

    public function pendingTotal(): int
    {
        return (int) $this->transactions()->where('status', TransactionStatus::Pending)->sum('amount');
    }

    /**
     * The sum of completed transactions after one day (exclusive) up to another (inclusive).
     */
    private function completedTotal(?string $after, string $upTo): int
    {
        return (int) $this->transactions()
            ->where('status', TransactionStatus::Completed)
            ->when($after !== null, fn (Builder $query) => $query->where('date', '>', $after))
            ->where('date', '<=', $upTo)
            ->sum('amount');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'archived_at' => 'datetime',
        ];
    }
}
