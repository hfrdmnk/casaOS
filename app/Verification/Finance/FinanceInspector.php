<?php

namespace App\Verification\Finance;

use App\Actions\Finance\Money;
use App\Actions\Finance\Transfers\ManageTransfers;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Payee;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use App\Verification\Contracts\Inspector;
use Illuminate\Database\Eloquent\Builder;

/**
 * The `finance` key of `verify:inspect`: accounts with balances and snapshots, transaction counts
 * by status and by who set their category and payee, transfers (counts by source, pairs and their
 * integrity, suggestions), categories, payees, rules, mappings and import batches. Money is shown
 * both in Rappen and as CHF.
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
            'transfers' => $this->transfers($user),
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
                        'set_category' => Category::query()->whereKey($action['value'] ?? 0)->value('name'),
                        'set_payee' => Payee::query()->whereKey($action['value'] ?? 0)->value('name'),
                        default => 'Transfer',
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
     * Transfer counts plus integrity checks: every pair points both ways, within one user, across
     * two accounts; `pairs_with_different_timestamps` and `pairs_with_unequal_amounts` count pairs
     * whose sides differ in date and time or do not cancel out (only possible when paired by hand).
     *
     * @return array<string, mixed>
     */
    private function transfers(User $user): array
    {
        $paired = Transaction::query()->ownedBy($user)->whereNotNull('transfer_counterpart_id')->with(['account', 'transferCounterpart.account'])->orderBy('id')->get();
        // One entry per pair: the side with the lower amount (the outflow), then the lower id.
        $pairs = $paired->filter(fn (Transaction $transaction): bool => $transaction->transferCounterpart !== null
            && [$transaction->amount, $transaction->id] < [$transaction->transferCounterpart->amount, $transaction->transferCounterpart->id]);
        $orphans = $paired->filter(fn (Transaction $transaction): bool => $transaction->transferCounterpart === null
            || $transaction->transferCounterpart->transfer_counterpart_id !== $transaction->id
            || $transaction->transferCounterpart->user_id !== $user->id
            || $transaction->transferCounterpart->account_id === $transaction->account_id
            || ! $transaction->is_transfer)->count()
            + Transaction::query()->ownedBy($user)->whereNull('transfer_counterpart_id')->where('transfer_source', 'auto')->count();
        $suggestions = TransferSuggestion::query()->ownedBy($user);
        $side = fn (Transaction $transaction): array => [
            'id' => $transaction->id,
            'account' => $transaction->account->name,
            'date' => $transaction->date,
            'time' => $transaction->time,
            'description' => $transaction->description,
            'amount' => Money::format($transaction->amount),
        ];

        return [
            'marked' => Transaction::query()->ownedBy($user)->where('is_transfer', true)->count(),
            'by_source' => $this->countsBy(Transaction::query()->ownedBy($user)->where('is_transfer', true), 'transfer_source'),
            'pairs' => $pairs->count(),
            'pairs_by_source' => $pairs->countBy(fn (Transaction $transaction): string => $transaction->transfer_source?->value.'+'.$transaction->transferCounterpart?->transfer_source?->value)->sortKeys()->all(),
            'without_counterpart' => Transaction::query()->ownedBy($user)->where('is_transfer', true)->whereNull('transfer_counterpart_id')->count(),
            'unmarked_by_hand' => Transaction::query()->ownedBy($user)->where('is_transfer', false)->where('transfer_source', 'manual')->count(),
            'pairs_with_different_timestamps' => $pairs->filter(fn (Transaction $transaction): bool => $transaction->date !== $transaction->transferCounterpart?->date || $transaction->time !== $transaction->transferCounterpart->time)->count(),
            'pairs_with_unequal_amounts' => $pairs->filter(fn (Transaction $transaction): bool => $transaction->amount + (int) $transaction->transferCounterpart?->amount !== 0)->count(),
            'orphans' => $orphans,
            'suggestions_open' => app(ManageTransfers::class)->openSuggestions((clone $suggestions))->count(),
            'suggestions_dismissed' => (clone $suggestions)->whereNotNull('dismissed_at')->count(),
            'suggestions' => app(ManageTransfers::class)->suggestions($user)->take(20)->map(fn (TransferSuggestion $suggestion): array => [
                'id' => $suggestion->id,
                'outflow' => $side($suggestion->outflow),
                'inflow' => $side($suggestion->inflow),
            ])->values()->all(),
            'pair_list' => $pairs->map(fn (Transaction $transaction): array => [
                'outflow' => $side($transaction),
                'inflow' => $side($transaction->transferCounterpart ?? $transaction),
                'source' => $transaction->transfer_source?->value.'+'.$transaction->transferCounterpart?->transfer_source?->value,
            ])->values()->all(),
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
     * @param  'status'|'category_source'|'payee_source'|'transfer_source'  $column
     * @return array<string, int>
     */
    private function countsBy(Builder $query, string $column): array
    {
        return $query->toBase()->selectRaw("{$column}, count(*) as aggregate")->groupBy($column)->pluck('aggregate', $column)
            ->map(fn (mixed $count): int => (int) $count)->all();
    }
}
