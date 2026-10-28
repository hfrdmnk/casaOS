<?php

namespace App\Verification\Finance;

use App\Actions\Finance\Money;
use App\Models\Finance\Account;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Transaction;
use App\Models\User;
use App\Verification\Contracts\Inspector;
use Illuminate\Database\Eloquent\Builder;

/**
 * The `finance` key of `verify:inspect`: accounts with balances and snapshots, transaction counts
 * by status, categories, mappings and import batches. Money is shown both in Rappen and as CHF.
 */
class FinanceInspector implements Inspector
{
    public function key(): string
    {
        return 'finance';
    }

    public function inspect(User $user): array
    {
        $today = today()->toDateString();

        return [
            'accounts' => Account::query()->ownedBy($user)->orderBy('id')->get()->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'currency' => $account->currency,
                'archived' => $account->isArchived(),
                'balance_today' => Money::format($account->balanceOn($today)),
                'balance_today_rappen' => $account->balanceOn($today),
                'pending_total' => Money::format($account->pendingTotal()),
                'transactions' => $this->countsByStatus($account->transactions()->getQuery()),
                'snapshots' => array_map(fn (array $row): array => [
                    'date' => $row['snapshot']->date,
                    'balance' => Money::format($row['snapshot']->balance),
                    'source' => $row['snapshot']->source->value,
                    'difference' => $row['difference'] === null ? null : Money::format($row['difference']),
                ], $account->reconciliation()),
            ])->all(),
            'transactions' => [
                'total' => Transaction::query()->ownedBy($user)->count(),
                'by_status' => $this->countsByStatus(Transaction::query()->ownedBy($user)),
                'uncategorized' => Transaction::query()->ownedBy($user)->whereNull('category_id')->count(),
                'with_fee' => Transaction::query()->ownedBy($user)->where('fee', '!=', 0)->count(),
            ],
            'categories' => $user->categories()->orderBy('name')->pluck('name')->all(),
            'mappings' => ImportMapping::query()->ownedBy($user)->with('account')->orderBy('id')->get()->map(fn (ImportMapping $mapping): array => [
                'id' => $mapping->id,
                'name' => $mapping->name,
                'account' => $mapping->account->name,
                'filter' => $mapping->filter_column === null ? null : ($mapping->header[$mapping->filter_column] ?? '').' = '.$mapping->filter_value,
            ])->all(),
            'batches' => ImportBatch::query()->ownedBy($user)->orderBy('id')->get()->map(fn (ImportBatch $batch): array => [
                'id' => $batch->id,
                'filename' => $batch->filename,
                'undone' => $batch->undone_at !== null,
                'transactions' => $batch->transactions()->count(),
                ...array_diff_key($batch->summary, ['accounts' => true, 'currency_rows' => true]),
            ])->all(),
        ];
    }

    /**
     * @param  Builder<Transaction>  $query
     * @return array<string, int>
     */
    private function countsByStatus(Builder $query): array
    {
        return $query->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)->all();
    }
}
