<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Money;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\Payee;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;

/**
 * How finance rows look to an MCP client. Every amount is an integer in Rappen under its own key,
 * with a formatted CHF string next to it under `<key>_chf`.
 */
final class Present
{
    /**
     * @return array<string, int|string|null>
     */
    public static function money(string $key, ?int $rappen): array
    {
        return [$key => $rappen, "{$key}_chf" => $rappen === null ? null : Money::chf($rappen)];
    }

    /**
     * An account with its balance today (completed transactions from the nearest snapshot), the
     * total of pending transactions and the reconciliation of its latest snapshot.
     *
     * @return array<string, mixed>
     */
    public static function account(Account $account): array
    {
        $reconciliation = $account->reconciliation();
        $latest = end($reconciliation) ?: null;

        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'currency' => $account->currency,
            'archived' => $account->isArchived(),
            ...self::money('balance_today', $account->balanceOn(today()->toDateString())),
            ...self::money('pending_total', $account->pendingTotal()),
            'transactions_count' => $account->transactions()->count(),
            'latest_snapshot' => $latest === null ? null : [
                'date' => $latest['snapshot']->date,
                ...self::money('balance', $latest['snapshot']->balance),
                ...self::money('difference', $latest['difference']),
            ],
            'mismatched_snapshots' => count(array_filter($reconciliation, fn (array $row): bool => ($row['difference'] ?? 0) !== 0)),
        ];
    }

    /**
     * Every snapshot of the account, newest first. `expected` is the previous snapshot plus the
     * completed transactions in between; `difference` is recorded minus expected (null for the
     * oldest snapshot).
     *
     * @return list<array<string, mixed>>
     */
    public static function snapshots(Account $account): array
    {
        return array_reverse(array_map(fn (array $row): array => [
            'id' => $row['snapshot']->id,
            'date' => $row['snapshot']->date,
            ...self::money('balance', $row['snapshot']->balance),
            'source' => $row['snapshot']->source->value,
            ...self::money('expected', $row['expected']),
            ...self::money('difference', $row['difference']),
        ], $account->reconciliation()));
    }

    /**
     * @return array<string, mixed>
     */
    public static function transaction(Transaction $transaction): array
    {
        $transaction->loadMissing(['account', 'category', 'payee', 'transferCounterpart.account']);
        $counterpart = $transaction->transferCounterpart;

        return [
            'id' => $transaction->id,
            'account_id' => $transaction->account_id,
            'account' => $transaction->account->name,
            'date' => $transaction->date,
            'time' => $transaction->time,
            'description' => $transaction->description,
            ...self::money('amount', $transaction->amount),
            ...self::money('fee', $transaction->fee),
            'currency' => $transaction->currency,
            'status' => $transaction->status->value,
            'category_id' => $transaction->category_id,
            'category' => $transaction->category?->name,
            'category_source' => $transaction->category_source?->value,
            'payee_id' => $transaction->payee_id,
            'payee' => $transaction->payee?->name,
            'payee_source' => $transaction->payee_source?->value,
            'is_transfer' => $transaction->is_transfer,
            'transfer_source' => $transaction->transfer_source?->value,
            'counterpart' => $counterpart === null ? null : [
                'id' => $counterpart->id,
                'account' => $counterpart->account->name,
                'date' => $counterpart->date,
                'description' => $counterpart->description,
                ...self::money('amount', $counterpart->amount),
            ],
            'import_batch_id' => $transaction->import_batch_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'transactions_count' => $category->transactions_count ?? $category->transactions()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payee(Payee $payee): array
    {
        return [
            'id' => $payee->id,
            'name' => $payee->name,
            'transactions_count' => $payee->transactions_count ?? $payee->transactions()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rule(Rule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'position' => $rule->position,
            'enabled' => $rule->enabled,
            'conditions' => $rule->conditions,
            'actions' => $rule->actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function suggestion(TransferSuggestion $suggestion): array
    {
        return [
            'id' => $suggestion->id,
            'outflow' => self::transaction($suggestion->outflow),
            'inflow' => self::transaction($suggestion->inflow),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function batch(ImportBatch $batch, ?ImportBatch $blocker = null): array
    {
        return [
            'id' => $batch->id,
            'filename' => $batch->filename,
            'created_at' => $batch->created_at?->toIso8601String(),
            'undone_at' => $batch->undone_at?->toIso8601String(),
            'summary' => $batch->summary,
            'blocked_by' => $blocker === null ? null : ['id' => $blocker->id, 'filename' => $blocker->filename],
        ];
    }

    /**
     * The user's category and payee names by id, for changes().
     *
     * @return array{category: array<int, string>, payee: array<int, string>}
     */
    public static function names(User $user): array
    {
        return [
            'category' => Category::query()->ownedBy($user)->pluck('name', 'id')->all(),
            'payee' => Payee::query()->ownedBy($user)->pluck('name', 'id')->all(),
        ];
    }

    /**
     * The changes a rule run makes to one transaction, with category and payee names.
     *
     * @param  array{category: array<int, string>, payee: array<int, string>}  $names  from names()
     * @param  array<string, array{from: int|null, to: int|null}>  $changes  keyed `category`, `payee`, `transfer`
     * @return list<array{field: string, from: int|null, to: int|null, from_name: string|null, to_name: string|null}>
     */
    public static function changes(array $names, array $changes): array
    {
        $rows = [];

        foreach ($changes as $field => $change) {
            $lookup = $field === 'transfer' ? [] : $names[$field] ?? [];
            $rows[] = [
                'field' => $field,
                'from' => $change['from'],
                'to' => $change['to'],
                'from_name' => $change['from'] === null ? null : ($lookup[$change['from']] ?? null),
                'to_name' => $change['to'] === null ? null : ($lookup[$change['to']] ?? null),
            ];
        }

        return $rows;
    }
}
