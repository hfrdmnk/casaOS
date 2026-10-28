<?php

use App\Models\Finance\Account;
use App\Models\Finance\AccountType;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\Category;
use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionInterval;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\Finance\TransferSource;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->travelTo('2026-10-28 12:00:00');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('a user without accounts gets the empty state', function () {
    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('dashboard')
        ->where('summary.has_accounts', false)
        ->where('summary.net_worth.total', 0));
});

test('the dashboard adds up net worth, spending, subscriptions and what needs attention', function () {
    $current = Account::factory()->for($this->user)->create(['name' => 'Current']);
    $savings = Account::factory()->for($this->user)->type(AccountType::Savings)->create(['name' => 'Savings']);
    $broker = Account::factory()->for($this->user)->type(AccountType::Investment)->create(['name' => 'Broker']);
    $archived = Account::factory()->for($this->user)->create(['name' => 'Old', 'archived_at' => now()]);
    $groceries = Category::query()->ownedBy($this->user)->where('name', 'Groceries')->firstOrFail();
    $dining = Category::query()->ownedBy($this->user)->where('name', 'Dining')->firstOrFail();

    BalanceSnapshot::factory()->for($current)->create(['date' => '2026-09-30', 'balance' => 100000]);
    // The broker's only snapshot is 58 days old: stale.
    BalanceSnapshot::factory()->for($broker)->create(['date' => '2026-08-31', 'balance' => 500000]);
    BalanceSnapshot::factory()->for($archived)->create(['date' => '2026-10-01', 'balance' => 999900]);
    // Savings recorded 200.00 on Oct 20, but the transactions since Oct 1 only explain 150.00: flagged.
    BalanceSnapshot::factory()->for($savings)->create(['date' => '2026-10-01', 'balance' => 10000]);
    BalanceSnapshot::factory()->for($savings)->create(['date' => '2026-10-20', 'balance' => 20000]);

    $row = fn (Account $account, string $date, int $amount, array $attributes = []) => Transaction::factory()->for($account)->create(['date' => $date, 'amount' => $amount, ...$attributes]);
    $row($current, '2026-10-02', -4530, ['category_id' => $groceries->id]);
    $row($current, '2026-10-15', -1270, ['category_id' => $groceries->id]);
    $row($current, '2026-10-05', -2500, ['category_id' => $dining->id]);
    $row($current, '2026-10-06', -999);
    $row($current, '2026-10-01', 300000, ['category_id' => null]);
    // Not spending: a transfer, a pending charge, last month, and the savings deposit (income side).
    $transferOut = $row($current, '2026-10-10', -5000, ['is_transfer' => true, 'transfer_source' => TransferSource::Auto]);
    $transferIn = $row($savings, '2026-10-10', 5000, ['is_transfer' => true, 'transfer_source' => TransferSource::Auto]);
    $transferOut->forceFill(['transfer_counterpart_id' => $transferIn->id])->save();
    $transferIn->forceFill(['transfer_counterpart_id' => $transferOut->id])->save();
    $row($current, '2026-10-20', -800, ['status' => TransactionStatus::Pending, 'category_id' => $groceries->id]);
    $row($current, '2026-09-28', -7000, ['category_id' => $groceries->id]);
    TransferSuggestion::query()->forceCreate([
        'user_id' => $this->user->id,
        'outflow_id' => $row($current, '2026-10-21', -1000)->id,
        'inflow_id' => $row($savings, '2026-10-21', 1000)->id,
    ]);

    Subscription::factory()->for($this->user)->create(['name' => 'Streamflix', 'amount' => 1590, 'interval' => SubscriptionInterval::Monthly, 'next_date' => '2026-11-03']);
    Subscription::factory()->for($this->user)->create(['name' => 'Domain', 'amount' => 1800, 'interval' => SubscriptionInterval::Yearly, 'next_date' => '2027-09-30']);
    Subscription::factory()->for($this->user)->create(['name' => 'Gym', 'amount' => 4900, 'interval' => SubscriptionInterval::Monthly, 'next_date' => '2026-10-26']);
    Subscription::factory()->for($this->user)->cancelled()->create(['name' => 'Old box', 'amount' => 3000, 'next_date' => '2026-11-01']);

    // Current: 1000.00 on Sep 30, then Oct: -45.30 -12.70 -25.00 -9.99 +3000 -50.00 -10.00 = 3847.01 (pending excluded).
    // Savings: 200.00 on Oct 20, +10.00 on Oct 21 = 210.00. Broker 5000.00. Archived excluded.
    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summary.has_accounts', true)
        ->where('summary.net_worth.total', 384701 + 21000 + 500000)
        ->where('summary.net_worth.accounts', 3)
        ->where('summary.net_worth.stale_since', '2026-08-31')
        ->where('summary.account_groups.0.type', 'checking')
        ->where('summary.account_groups.0.total', 384701)
        ->where('summary.account_groups.1.accounts.0.name', 'Savings')
        ->where('summary.account_groups.1.accounts.0.mismatched_snapshots', 1)
        ->where('summary.account_groups.2.type', 'investment')
        ->where('summary.spending.month', '2026-10')
        ->where('summary.spending.total', 4530 + 1270 + 2500 + 999 + 1000)
        ->where('summary.spending.categories', [
            ['category_id' => $groceries->id, 'category' => 'Groceries', 'amount' => 5800, 'count' => 2],
            ['category_id' => $dining->id, 'category' => 'Dining', 'amount' => 2500, 'count' => 1],
            ['category_id' => null, 'category' => 'Uncategorized', 'amount' => 1999, 'count' => 2],
        ])
        // Monthly 15.90 + 49.00 + 18.00/12 = 66.40; the cancelled one is left out.
        ->where('summary.subscriptions.monthly_total', 1590 + 4900 + 150)
        ->where('summary.subscriptions.upcoming', [
            ['id' => Subscription::query()->where('name', 'Gym')->value('id'), 'name' => 'Gym', 'amount' => 4900, 'interval' => 'monthly', 'next_date' => '2026-10-26', 'overdue' => true],
            ['id' => Subscription::query()->where('name', 'Streamflix')->value('id'), 'name' => 'Streamflix', 'amount' => 1590, 'interval' => 'monthly', 'next_date' => '2026-11-03', 'overdue' => false],
        ])
        // Uncategorized: the -9.99 charge, the 3000 income and both sides of the open suggestion; transfers are not counted.
        ->where('summary.attention', ['uncategorized' => 4, 'transfer_suggestions' => 1, 'subscription_suggestions' => 0]));

    $this->get(route('dashboard', ['month' => '2026-09']))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summary.spending.total', 7000)
        ->where('summary.spending.categories.0.category', 'Groceries'));

    $this->get(route('dashboard', ['month' => '2026-13']))->assertSessionHasErrors('month');
});

test('another user\'s rows never reach the dashboard', function () {
    $other = User::factory()->create();
    $account = Account::factory()->for($other)->create();
    BalanceSnapshot::factory()->for($account)->create(['date' => '2026-10-01', 'balance' => 100000]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-02', 'amount' => -5000]);
    Subscription::factory()->for($other)->create(['next_date' => '2026-11-01']);

    $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('summary.has_accounts', false)
        ->where('summary.spending.total', 0)
        ->where('summary.subscriptions.monthly_total', 0)
        ->where('summary.attention.uncategorized', 0));
});
