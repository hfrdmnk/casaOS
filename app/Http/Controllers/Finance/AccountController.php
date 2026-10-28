<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\AccountRequest;
use App\Models\Finance\Account;
use App\Models\Finance\AccountType;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    /**
     * List the user's accounts with their current balance.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $accounts = Account::query()->ownedBy($user)
            ->withCount('transactions')
            ->orderByRaw('archived_at is not null')
            ->orderBy('name')
            ->get();

        return Inertia::render('finance/accounts/index', [
            'accounts' => $accounts->map(fn (Account $account): array => self::summary($account))->all(),
            'types' => AccountType::options(),
        ]);
    }

    public function store(AccountRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $account = $user->accounts()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account created.')]);

        return to_route('finance.accounts.show', $account);
    }

    /**
     * One account: balance, snapshots with their reconciliation, mappings, recent transactions.
     */
    public function show(Account $account): Response
    {
        $account->loadCount('transactions');

        return Inertia::render('finance/accounts/show', [
            'account' => self::summary($account),
            'types' => AccountType::options(),
            'snapshots' => array_reverse(array_map(fn (array $row): array => [
                'id' => $row['snapshot']->id,
                'date' => $row['snapshot']->date,
                'balance' => $row['snapshot']->balance,
                'source' => $row['snapshot']->source->value,
                'expected' => $row['expected'],
                'difference' => $row['difference'],
            ], $account->reconciliation())),
            'mappings' => $account->mappings()->orderBy('name')->get()
                ->map(fn (ImportMapping $mapping): array => ['id' => $mapping->id, 'name' => $mapping->name])->all(),
            'recentTransactions' => $account->transactions()->with('category')
                ->orderByDesc('date')->orderByDesc('time')->orderByDesc('id')->limit(10)->get()
                ->map(fn (Transaction $transaction): array => TransactionController::present($transaction))->all(),
        ]);
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        $account->update($request->safe()->only(['name', 'type']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account updated.')]);

        return to_route('finance.accounts.show', $account);
    }

    public function archive(Account $account): RedirectResponse
    {
        $account->update(['archived_at' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account archived.')]);

        return to_route('finance.accounts.show', $account);
    }

    public function unarchive(Account $account): RedirectResponse
    {
        $account->update(['archived_at' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account restored.')]);

        return to_route('finance.accounts.show', $account);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Account $account): array
    {
        $reconciliation = $account->reconciliation();
        $latest = end($reconciliation) ?: null;

        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'type_label' => $account->type->label(),
            'importable' => $account->type->isImportable(),
            'currency' => $account->currency,
            'archived' => $account->isArchived(),
            'balance' => $account->balanceOn(today()->toDateString()),
            'pending_total' => $account->pendingTotal(),
            'transactions_count' => $account->transactions_count ?? $account->transactions()->count(),
            'latest_snapshot' => $latest === null ? null : [
                'date' => $latest['snapshot']->date,
                'balance' => $latest['snapshot']->balance,
            ],
            'mismatched_snapshots' => count(array_filter($reconciliation, fn (array $row): bool => ($row['difference'] ?? 0) !== 0)),
        ];
    }
}
