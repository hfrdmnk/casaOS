<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Rules\EditTransaction;
use App\Actions\Finance\Transfers\ManageTransfers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\TransactionUpdateRequest;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Payee;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    /**
     * The transaction list. Filters: `account`, `category` (an id, or `none` for uncategorized),
     * `from` and `to` (Y-m-d, inclusive), `search` (in the raw description or the payee name) and
     * `transfer` (`yes`, `no`, or `suggested` for rows in an open transfer suggestion).
     */
    public function index(Request $request, ManageTransfers $transfers): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->validate([
            'account' => ['nullable', 'integer'],
            'category' => ['nullable', 'regex:/^(none|\d+)$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:200'],
            'transfer' => ['nullable', 'in:yes,no,suggested'],
        ]);

        $suggested = fn () => $transfers->openSuggestions(TransferSuggestion::query()->ownedBy($user));

        $transactions = Transaction::query()->ownedBy($user)
            ->with(['account', 'category', 'payee', 'transferCounterpart.account'])
            ->when($filters['account'] ?? null, fn ($query, $account) => $query->where('account_id', $account))
            ->when($filters['category'] ?? null, fn ($query, $category) => $category === 'none'
                ? $query->whereNull('category_id')
                : $query->where('category_id', $category))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('date', '<=', $to))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->whereLike('description', '%'.$search.'%', caseSensitive: false)
                ->orWhereHas('payee', fn ($query) => $query->whereLike('name', '%'.$search.'%', caseSensitive: false))))
            ->when($filters['transfer'] ?? null, fn ($query, $transfer) => match ($transfer) {
                'yes' => $query->where('is_transfer', true),
                'no' => $query->where('is_transfer', false),
                default => $query->where(fn ($query) => $query
                    ->whereIn('id', $suggested()->select('outflow_id'))
                    ->orWhereIn('id', $suggested()->select('inflow_id'))),
            })
            ->orderByDesc('date')->orderByDesc('time')->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $ids = $transactions->getCollection()->modelKeys();
        $inSuggestion = array_flip([
            ...$suggested()->whereIn('outflow_id', $ids)->pluck('outflow_id')->all(),
            ...$suggested()->whereIn('inflow_id', $ids)->pluck('inflow_id')->all(),
        ]);

        $transactions->through(fn (Transaction $transaction): array => [
            ...self::present($transaction),
            'suggested_transfer' => isset($inSuggestion[$transaction->id]),
        ]);

        return Inertia::render('finance/transactions/index', [
            'transactions' => $transactions,
            'filters' => [
                'account' => isset($filters['account']) ? (string) $filters['account'] : '',
                'category' => (string) ($filters['category'] ?? ''),
                'from' => (string) ($filters['from'] ?? ''),
                'to' => (string) ($filters['to'] ?? ''),
                'search' => (string) ($filters['search'] ?? ''),
                'transfer' => (string) ($filters['transfer'] ?? ''),
            ],
            'suggestions_count' => $suggested()->count(),
            'accounts' => Account::query()->ownedBy($user)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name])->all(),
            'categories' => self::categoryOptions($user),
            'payees' => self::payeeOptions($user),
        ]);
    }

    /**
     * Set a transaction's category or payee by hand; clearing one hands it back to the rules.
     */
    public function update(TransactionUpdateRequest $request, Transaction $transaction, EditTransaction $edit): RedirectResponse
    {
        /** @var array{category_id?: int|null, payee_id?: int|null} $fields */
        $fields = $request->validated();
        $ruled = $edit($transaction, $fields);
        $field = array_key_exists('payee_id', $fields) ? 'Payee' : 'Category';

        Inertia::flash('toast', ['type' => 'success', 'message' => $ruled === []
            ? __(':field saved.', ['field' => $field])
            : __(':field saved. Rules filled in the cleared value.', ['field' => $field])]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'account_id' => $transaction->account_id,
            'account' => $transaction->account->name,
            'date' => $transaction->date,
            'time' => $transaction->time,
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'fee' => $transaction->fee,
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
            'counterpart' => $transaction->transferCounterpart === null ? null : [
                'id' => $transaction->transferCounterpart->id,
                'account' => $transaction->transferCounterpart->account->name,
                'date' => $transaction->transferCounterpart->date,
                'time' => $transaction->transferCounterpart->time,
                'description' => $transaction->transferCounterpart->description,
                'amount' => $transaction->transferCounterpart->amount,
            ],
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function categoryOptions(User $user): array
    {
        return Category::query()->ownedBy($user)->orderBy('name')->get()
            ->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
            ->values()->all();
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function payeeOptions(User $user): array
    {
        return Payee::query()->ownedBy($user)->orderBy('name')->get()
            ->map(fn (Payee $payee): array => ['id' => $payee->id, 'name' => $payee->name])
            ->values()->all();
    }
}
