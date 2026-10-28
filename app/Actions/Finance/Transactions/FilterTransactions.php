<?php

namespace App\Actions\Finance\Transactions;

use App\Actions\Finance\Transfers\ManageTransfers;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters of the transaction list, shared by the web list and the MCP tools:
 *
 * - `account`: an account id
 * - `category`: a category id, or `none` for uncategorized
 * - `payee`: a payee id, or `none` for rows without a payee
 * - `from`, `to`: Y-m-d, inclusive
 * - `search`: text in the raw description or the payee name
 * - `transfer`: `yes`, `no`, or `suggested` for rows in an open transfer suggestion
 *
 * Ids of another user's rows simply match nothing.
 */
final class FilterTransactions
{
    public function __construct(private ManageTransfers $transfers) {}

    /**
     * Validation rules for the filters.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'account' => ['nullable', 'integer'],
            'category' => ['nullable', 'regex:/^(none|\d+)$/'],
            'payee' => ['nullable', 'regex:/^(none|\d+)$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:200'],
            'transfer' => ['nullable', 'in:yes,no,suggested'],
        ];
    }

    /**
     * The user's transactions matching the filters, newest first.
     *
     * @param  array<string, mixed>  $filters  validated with rules()
     * @return Builder<Transaction>
     */
    public function query(User $user, array $filters): Builder
    {
        return Transaction::query()->ownedBy($user)
            ->with(['account', 'category', 'payee', 'transferCounterpart.account'])
            ->when($filters['account'] ?? null, fn (Builder $query, mixed $account) => $query->where('account_id', (int) $account))
            ->when($filters['category'] ?? null, fn (Builder $query, mixed $category) => $category === 'none'
                ? $query->whereNull('category_id')
                : $query->where('category_id', (int) $category))
            ->when($filters['payee'] ?? null, fn (Builder $query, mixed $payee) => $payee === 'none'
                ? $query->whereNull('payee_id')
                : $query->where('payee_id', (int) $payee))
            ->when($filters['from'] ?? null, fn (Builder $query, mixed $from) => $query->where('date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, mixed $to) => $query->where('date', '<=', $to))
            ->when($filters['search'] ?? null, fn (Builder $query, mixed $search) => $query->where(fn (Builder $query) => $query
                ->whereLike('description', '%'.$search.'%', caseSensitive: false)
                ->orWhereHas('payee', fn (Builder $query) => $query->whereLike('name', '%'.$search.'%', caseSensitive: false))))
            ->when($filters['transfer'] ?? null, fn (Builder $query, mixed $transfer) => match ($transfer) {
                'yes' => $query->where('is_transfer', true),
                'no' => $query->where('is_transfer', false),
                default => $query->where(fn (Builder $query) => $query
                    ->whereIn('id', $this->suggested($user)->select('outflow_id'))
                    ->orWhereIn('id', $this->suggested($user)->select('inflow_id'))),
            })
            ->orderByDesc('date')->orderByDesc('time')->orderByDesc('id');
    }

    /**
     * Which of these transaction ids are a side of an open transfer suggestion.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int|string, int> keyed by id
     */
    public function inSuggestion(User $user, array $ids): array
    {
        return array_flip([
            ...$this->suggested($user)->whereIn('outflow_id', $ids)->pluck('outflow_id')->all(),
            ...$this->suggested($user)->whereIn('inflow_id', $ids)->pluck('inflow_id')->all(),
        ]);
    }

    /**
     * @return Builder<TransferSuggestion>
     */
    private function suggested(User $user): Builder
    {
        return $this->transfers->openSuggestions(TransferSuggestion::query()->ownedBy($user));
    }
}
