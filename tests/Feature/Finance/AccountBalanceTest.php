<?php

use App\Models\Finance\Account;
use App\Models\Finance\AccountType;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

/**
 * @param  list<array{0: string, 1: int}>  $rows  [date, amount]
 */
function transactionsOn(Account $account, array $rows): void
{
    foreach ($rows as [$date, $amount]) {
        Transaction::factory()->for($account)->create(['date' => $date, 'amount' => $amount]);
    }
}

test('a start balance followed by imports adds the transactions after it', function () {
    BalanceSnapshot::factory()->for($this->account)->create(['date' => '2026-09-30', 'balance' => 100000]);
    transactionsOn($this->account, [['2026-09-30', -9999], ['2026-10-02', -2500], ['2026-10-05', 30000]]);

    expect($this->account->balanceOn('2026-10-03'))->toBe(97500)
        ->and($this->account->balanceOn('2026-10-31'))->toBe(127500);
});

test('imports followed by today\'s balance work backwards from the snapshot', function () {
    transactionsOn($this->account, [['2026-10-02', -2500], ['2026-10-05', 30000], ['2026-10-20', -1000]]);
    BalanceSnapshot::factory()->for($this->account)->create(['date' => '2026-10-20', 'balance' => 50000]);

    expect($this->account->balanceOn('2026-10-20'))->toBe(50000)
        ->and($this->account->balanceOn('2026-10-05'))->toBe(51000)
        ->and($this->account->balanceOn('2026-10-01'))->toBe(23500);
});

test('pending transactions are not part of the balance', function () {
    BalanceSnapshot::factory()->for($this->account)->create(['date' => '2026-10-01', 'balance' => 10000]);
    Transaction::factory()->for($this->account)->pending()->create(['date' => '2026-10-02', 'amount' => -500]);

    expect($this->account->balanceOn('2026-10-31'))->toBe(10000)
        ->and($this->account->pendingTotal())->toBe(-500);
});

test('a snapshot that disagrees with the computed balance shows its difference on the account', function () {
    $this->post(route('finance.snapshots.store', $this->account), ['date' => '2026-10-01', 'balance' => "1'000.00"])->assertSessionHasNoErrors();
    transactionsOn($this->account, [['2026-10-10', -20000]]);
    $this->post(route('finance.snapshots.store', $this->account), ['date' => '2026-10-31', 'balance' => '790.50']);

    $this->get(route('finance.accounts.show', $this->account))
        ->assertInertia(fn ($page) => $page
            ->where('account.mismatched_snapshots', 1)
            ->where('snapshots.0.expected', 80000)
            ->where('snapshots.0.difference', -950));
});

test('entering a balance for a day that has one replaces it', function () {
    $this->post(route('finance.snapshots.store', $this->account), ['date' => '2026-10-01', 'balance' => '10']);
    $this->post(route('finance.snapshots.store', $this->account), ['date' => '2026-10-01', 'balance' => '-80.05']);

    expect($this->account->snapshots()->sole()->balance)->toBe(-8005);
});

test('a balance in the wrong notation is rejected', function () {
    $this->post(route('finance.snapshots.store', $this->account), ['date' => '2026-10-01', 'balance' => '12,50'])
        ->assertSessionHasErrors(['balance' => 'Enter the balance in CHF, e.g. 1234.50 or -80.']);

    expect(BalanceSnapshot::count())->toBe(0);
});

test('accounts are created in CHF, edited and archived', function () {
    $this->post(route('finance.accounts.store'), ['name' => 'Broker', 'type' => 'investment'])->assertSessionHasNoErrors();
    $broker = Account::query()->where('name', 'Broker')->sole();

    $this->patch(route('finance.accounts.update', $broker), ['name' => 'Broker AG', 'type' => 'crypto']);
    $this->post(route('finance.accounts.archive', $broker));

    $broker->refresh();
    expect($broker->only(['name', 'type', 'currency']))->toBe(['name' => 'Broker AG', 'type' => AccountType::Crypto, 'currency' => 'CHF'])
        ->and($broker->isArchived())->toBeTrue();

    $this->post(route('finance.accounts.store'), ['name' => 'Euro', 'type' => 'checking', 'currency' => 'EUR'])
        ->assertSessionHasErrors(['currency' => 'CHF is the only supported currency for now.']);
});
