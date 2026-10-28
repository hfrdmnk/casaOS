<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Rules\EditTransaction;
use App\Actions\Finance\Transactions\FilterTransactions;
use App\Actions\Finance\Transfers\ManageTransfers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\TransactionUpdateRequest;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Payee;
use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionInterval;
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
     * The transaction list, with the filters of FilterTransactions.
     */
    public function index(Request $request, FilterTransactions $filter, ManageTransfers $transfers): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->validate(FilterTransactions::rules());

        $transactions = $filter->query($user, $filters)
            ->paginate(50)
            ->withQueryString();

        $inSuggestion = $filter->inSuggestion($user, $transactions->getCollection()->modelKeys());

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
                'subscription' => (string) ($filters['subscription'] ?? ''),
            ],
            'suggestions_count' => $transfers->openSuggestions(TransferSuggestion::query()->ownedBy($user))->count(),
            'accounts' => Account::query()->ownedBy($user)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name])->all(),
            'categories' => self::categoryOptions($user),
            'payees' => self::payeeOptions($user),
            'subscriptions' => Subscription::query()->ownedBy($user)->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Subscription $subscription): array => ['id' => $subscription->id, 'name' => $subscription->name])->all(),
            'intervals' => SubscriptionInterval::options(),
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
            'subscription_id' => $transaction->subscription_id,
            'subscription' => $transaction->subscription?->name,
            'subscription_source' => $transaction->subscription_source?->value,
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
