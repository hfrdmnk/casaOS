<?php

namespace App\Verification\Finance;

use App\Actions\Finance\Money;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Payee;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\User;
use App\Verification\Contracts\Inspector;
use Illuminate\Database\Eloquent\Builder;

/**
 * The `finance` key of `verify:inspect`: accounts with balances and snapshots, transaction counts
 * by status and by who set their category and payee, categories, payees, rules, mappings and
 * import batches. Money is shown both in Rappen and as CHF.
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
                'without_payee' => Transaction::query()->ownedBy($user)->whereNull('payee_id')->count(),
                'category_source' => $this->countsBy(Transaction::query()->ownedBy($user)->whereNotNull('category_id'), 'category_source'),
                'payee_source' => $this->countsBy(Transaction::query()->ownedBy($user)->whereNotNull('payee_id'), 'payee_source'),
                'by_category' => Transaction::query()->ownedBy($user)->join('categories', 'categories.id', '=', 'transactions.category_id')
                    ->toBase()->selectRaw('categories.name, count(*) as aggregate')->groupBy('categories.name')->orderBy('categories.name')
                    ->pluck('aggregate', 'name')->map(fn (mixed $count): int => (int) $count)->all(),
                'with_fee' => Transaction::query()->ownedBy($user)->where('fee', '!=', 0)->count(),
            ],
            'categories' => $user->categories()->orderBy('name')->pluck('name')->all(),
            'payees' => Payee::query()->ownedBy($user)->withCount('transactions')->orderBy('name')->get()
                ->map(fn (Payee $payee): array => ['id' => $payee->id, 'name' => $payee->name, 'transactions' => $payee->transactions_count])->all(),
            'rules' => Rule::query()->ownedBy($user)->orderBy('position')->orderBy('id')->get()->map(fn (Rule $rule): array => [
                'id' => $rule->id,
                'position' => $rule->position,
                'name' => $rule->name,
                'enabled' => $rule->enabled,
                'conditions' => $rule->conditions,
                'actions' => array_map(fn (array $action): array => [
                    ...$action,
                    'label' => match ($action['type']) {
                        'set_category' => Category::query()->whereKey($action['value'])->value('name'),
                        default => Payee::query()->whereKey($action['value'])->value('name'),
                    },
                ], $rule->actions),
            ])->all(),
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
        return $this->countsBy($query, 'status');
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  'status'|'category_source'|'payee_source'  $column
     * @return array<string, int>
     */
    private function countsBy(Builder $query, string $column): array
    {
        return $query->toBase()->selectRaw("{$column}, count(*) as aggregate")->groupBy($column)->pluck('aggregate', $column)
            ->map(fn (mixed $count): int => (int) $count)->all();
    }
}
