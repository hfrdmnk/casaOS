<?php

namespace App\Actions\Finance\Dashboard;

use App\Actions\Finance\Transfers\ManageTransfers;
use App\Models\Finance\Account;
use App\Models\Finance\AccountType;
use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionSuggestion;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The numbers of the dashboard, shared by the web page and the `get_dashboard_summary` MCP tool.
 * Amounts are integers in Rappen.
 *
 * - Net worth: today's balance of every non-archived account (investment and crypto included),
 *   with the oldest latest-snapshot date among accounts whose newest snapshot is more than 45 days
 *   old (`stale_since`).
 * - Accounts by type, each with its balance and whether any snapshot disagrees with the balance
 *   computed from the one before it.
 * - Spending of one month by category: completed outflows that count as spending (no transfers),
 *   as positive Rappen, with an uncategorized bucket (`category_id` null).
 * - Upcoming subscriptions: active ones expected within 30 days from today (overdue ones too),
 *   and the monthly recurring cost of all active subscriptions.
 * - Needs attention: uncategorized spending or income rows, open transfer and subscription
 *   suggestions.
 */
final class DashboardSummary
{
    public const STALE_DAYS = 45;

    public const UPCOMING_DAYS = 30;

    public function __construct(private ManageTransfers $transfers) {}

    /**
     * @param  string  $month  Y-m
     * @return array{
     *     has_accounts: bool,
     *     today: string,
     *     net_worth: array{total: int, accounts: int, stale_since: string|null},
     *     account_groups: list<array{type: string, label: string, total: int, accounts: list<array{id: int, name: string, type: string, type_label: string, balance: int, latest_snapshot_date: string|null, mismatched_snapshots: int, latest_difference: int|null}>}>,
     *     spending: array{month: string, from: string, to: string, total: int, categories: list<array{category_id: int|null, category: string, amount: int, count: int}>},
     *     subscriptions: array{monthly_total: int, active: int, upcoming: list<array{id: int, name: string, amount: int, interval: string, next_date: string, overdue: bool}>, upcoming_total: int},
     *     attention: array{uncategorized: int, transfer_suggestions: int, subscription_suggestions: int}
     * }
     */
    public function __invoke(User $user, string $month): array
    {
        $today = today()->toDateString();
        $accounts = Account::query()->ownedBy($user)->active()->orderBy('name')->get();
        $rows = $accounts->map(function (Account $account) use ($today): array {
            $reconciliation = $account->reconciliation();
            $latest = end($reconciliation) ?: null;

            return [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'type_label' => $account->type->label(),
                'balance' => $account->balanceOn($today),
                'latest_snapshot_date' => $latest === null ? null : $latest['snapshot']->date,
                'mismatched_snapshots' => count(array_filter($reconciliation, fn (array $row): bool => ($row['difference'] ?? 0) !== 0)),
                'latest_difference' => $latest === null ? null : $latest['difference'],
            ];
        });

        $staleBefore = today()->subDays(self::STALE_DAYS)->toDateString();
        $stale = array_filter($rows->pluck('latest_snapshot_date')->all(), fn (?string $date): bool => $date !== null && $date < $staleBefore);
        $staleSince = $stale === [] ? null : (string) min($stale);

        $groups = [];

        foreach (AccountType::cases() as $type) {
            $ofType = $rows->where('type', $type->value)->values();

            if ($ofType->isNotEmpty()) {
                $groups[] = ['type' => $type->value, 'label' => $type->label(), 'total' => (int) $ofType->sum('balance'), 'accounts' => array_values($ofType->all())];
            }
        }

        $subscriptions = Subscription::query()->ownedBy($user)->active()->orderBy('next_date')->orderBy('name')->get();
        $upcomingUntil = today()->addDays(self::UPCOMING_DAYS)->toDateString();

        return [
            'has_accounts' => Account::query()->ownedBy($user)->exists(),
            'today' => $today,
            'net_worth' => [
                'total' => (int) $rows->sum('balance'),
                'accounts' => $rows->count(),
                'stale_since' => $staleSince,
            ],
            'account_groups' => $groups,
            'spending' => $this->spending($user, $month),
            'subscriptions' => [
                'monthly_total' => (int) $subscriptions->sum(fn (Subscription $subscription): int => $subscription->monthlyAmount()),
                'active' => $subscriptions->count(),
                'upcoming' => array_values($subscriptions->filter(fn (Subscription $subscription): bool => $subscription->next_date <= $upcomingUntil)
                    ->map(fn (Subscription $subscription): array => [
                        'id' => $subscription->id,
                        'name' => $subscription->name,
                        'amount' => $subscription->amount,
                        'interval' => $subscription->interval->value,
                        'next_date' => $subscription->next_date,
                        'overdue' => $subscription->next_date < $today,
                    ])->all()),
                'upcoming_total' => (int) $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->next_date <= $upcomingUntil)->sum('amount'),
            ],
            'attention' => [
                'uncategorized' => Transaction::query()->ownedBy($user)->countsAsIncomeOrSpending()->whereNull('category_id')->count(),
                'transfer_suggestions' => $this->transfers->openSuggestions(TransferSuggestion::query()->ownedBy($user))->count(),
                'subscription_suggestions' => SubscriptionSuggestion::query()->ownedBy($user)->whereNull('dismissed_at')->count(),
            ],
        ];
    }

    /**
     * @param  string  $month  Y-m
     * @return array{month: string, from: string, to: string, total: int, categories: list<array{category_id: int|null, category: string, amount: int, count: int}>}
     */
    public function spending(User $user, string $month): array
    {
        $from = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $rows = Transaction::query()->ownedBy($user)
            ->countsAsIncomeOrSpending()
            ->where('transactions.status', TransactionStatus::Completed)
            ->where('transactions.amount', '<', 0)
            ->whereBetween('transactions.date', [$from->toDateString(), $to->toDateString()])
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->toBase()
            ->selectRaw('transactions.category_id, categories.name, -sum(transactions.amount) as amount, count(*) as count')
            ->groupBy('transactions.category_id', 'categories.name')
            ->get()
            ->map(fn (object $row): array => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category' => (string) ($row->name ?? 'Uncategorized'),
                'amount' => (int) $row->amount,
                'count' => (int) $row->count,
            ])
            ->sortBy([['amount', 'desc'], ['category', 'asc']])
            ->values();

        return [
            'month' => $month,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total' => (int) $rows->sum('amount'),
            'categories' => array_values($rows->all()),
        ];
    }
}
