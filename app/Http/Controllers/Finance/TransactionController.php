<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\TransactionUpdateRequest;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
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
     * `from` and `to` (Y-m-d, inclusive) and `search` (in the description).
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
            ->with(['account', 'category'])
            ->when($filters['account'] ?? null, fn ($query, $account) => $query->where('account_id', $account))
            ->when($filters['category'] ?? null, fn ($query, $category) => $category === 'none'
                ? $query->whereNull('category_id')
                : $query->where('category_id', $category))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('date', '<=', $to))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereLike('description', '%'.$search.'%', caseSensitive: false))
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
        ]);
    }

    /**
     * Categorize a transaction by hand.
     */
    public function update(TransactionUpdateRequest $request, Transaction $transaction): RedirectResponse
    {
        $transaction->update(['category_id' => $request->validated('category_id')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category saved.')]);

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
}
