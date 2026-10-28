<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Rules\EditTransaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\TransactionUpdateRequest;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Payee;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    /**
     * The transaction list. Filters: `account`, `category` (an id, or `none` for uncategorized),
     * `from` and `to` (Y-m-d, inclusive) and `search` (in the raw description or the payee name).
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->validate([
            'account' => ['nullable', 'integer'],
            'category' => ['nullable', 'regex:/^(none|\d+)$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);

        $transactions = Transaction::query()->ownedBy($user)
            ->with(['account', 'category', 'payee'])
            ->when($filters['account'] ?? null, fn ($query, $account) => $query->where('account_id', $account))
            ->when($filters['category'] ?? null, fn ($query, $category) => $category === 'none'
                ? $query->whereNull('category_id')
                : $query->where('category_id', $category))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('date', '<=', $to))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
                ->whereLike('description', '%'.$search.'%', caseSensitive: false)
                ->orWhereHas('payee', fn ($query) => $query->whereLike('name', '%'.$search.'%', caseSensitive: false))))
            ->orderByDesc('date')->orderByDesc('time')->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Transaction $transaction): array => self::present($transaction));

        return Inertia::render('finance/transactions/index', [
            'transactions' => $transactions,
            'filters' => [
                'account' => isset($filters['account']) ? (string) $filters['account'] : '',
                'category' => (string) ($filters['category'] ?? ''),
                'from' => (string) ($filters['from'] ?? ''),
                'to' => (string) ($filters['to'] ?? ''),
                'search' => (string) ($filters['search'] ?? ''),
            ],
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
