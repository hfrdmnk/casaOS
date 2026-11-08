<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonPeriod;
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
        return $this->dailyBalances($date, $date)[$date];
    }

    /**
     * The balance at the end of every day from one day to another (Y-m-d, both inclusive), keyed
     * by day. Each day uses its nearest snapshot (the earlier one on a tie), as balanceOn() does.
     *
     * @return array<string, int>
     */
    public function dailyBalances(string $from, string $to): array
    {
        $snapshots = $this->snapshots()->orderBy('date')->get(['date', 'balance'])->all();
        $dates = [];
        $runningTotals = [];
        $running = 0;

        foreach ($this->transactions()->where('status', TransactionStatus::Completed)->toBase()
            ->selectRaw('date, sum(amount) as amount')->groupBy('date')->orderBy('date')->get() as $row) {
            $dates[] = (string) $row->date;
            $runningTotals[] = $running += (int) $row->amount;
        }

        $completedUpTo = function (string $day) use ($dates, $runningTotals): int {
            $low = 0;
            $high = count($dates) - 1;
            $total = 0;

            while ($low <= $high) {
                $middle = intdiv($low + $high, 2);

                if ($dates[$middle] <= $day) {
                    $total = $runningTotals[$middle];
                    $low = $middle + 1;
                } else {
                    $high = $middle - 1;
                }
            }

            return $total;
        };

        $balances = [];
        $next = 0;

        foreach (CarbonPeriod::create($from, $to) as $date) {
            $day = $date->toDateString();

            while ($next < count($snapshots) && $snapshots[$next]->date <= $day) {
                $next++;
            }

            $before = $snapshots[$next - 1] ?? null;
            $after = $snapshots[$next] ?? null;
            $nearest = $before === null || ($after !== null && $date->diffInDays($after->date) < Carbon::parse($before->date)->diffInDays($date))
                ? $after
                : $before;

            $balances[$day] = $nearest === null
                ? $completedUpTo($day)
                : $nearest->balance + $completedUpTo($day) - $completedUpTo($nearest->date);
        }

        return $balances;
    }

    /**
     * The day of the earliest snapshot or completed transaction, null when the account has neither.
     */
    public function firstActivityDate(): ?string
    {
        $dates = array_filter([
            $this->snapshots()->min('date'),
            $this->transactions()->where('status', TransactionStatus::Completed)->min('date'),
        ]);

        return $dates === [] ? null : (string) min($dates);
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
