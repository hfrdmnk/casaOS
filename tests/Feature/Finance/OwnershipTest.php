<?php

use App\Models\Finance\Account;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\Category;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Payee;
use App\Models\Finance\Rule;
use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionSuggestion;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('another user gets 404 on every finance row', function (string $method, string $route, string $model) {
    $owner = User::factory()->create();
    $account = Account::factory()->for($owner)->create();
    $rows = [
        'account' => $account,
        'transaction' => Transaction::factory()->for($account)->create(),
        'snapshot' => BalanceSnapshot::factory()->for($account)->create(),
        'category' => Category::factory()->for($owner)->create(),
        'mapping' => ImportMapping::factory()->for($account)->create(),
        'batch' => ImportBatch::factory()->for($owner)->create(),
        'payee' => Payee::factory()->for($owner)->create(),
        'rule' => Rule::factory()->for($owner)->create(),
    ];
    $rows['suggestion'] = TransferSuggestion::query()->forceCreate([
        'user_id' => $owner->id,
        'outflow_id' => Transaction::factory()->for($account)->create(['amount' => -500])->id,
        'inflow_id' => Transaction::factory()->for(Account::factory()->for($owner))->create(['amount' => 500])->id,
    ]);

    $rows['subscription'] = Subscription::factory()->for($owner)->create();
    $rows['subscription_suggestion'] = SubscriptionSuggestion::query()->forceCreate([
        'user_id' => $owner->id, 'match_key' => 'description:owner', 'name' => 'Owner', 'description_match' => 'owner',
        'amount' => 500, 'interval' => 'monthly', 'next_date' => '2026-11-01', 'transaction_ids' => [],
    ]);

    $this->actingAs(User::factory()->create())
        ->json($method, route($route, $rows[$model]))
        ->assertNotFound();

    expect($rows[$model]::query()->whereKey($rows[$model]->getKey())->exists())->toBeTrue();
})->with([
    'show account' => ['GET', 'finance.accounts.show', 'account'],
    'update account' => ['PATCH', 'finance.accounts.update', 'account'],
    'archive account' => ['POST', 'finance.accounts.archive', 'account'],
    'add snapshot' => ['POST', 'finance.snapshots.store', 'account'],
    'delete snapshot' => ['DELETE', 'finance.snapshots.destroy', 'snapshot'],
    'categorize transaction' => ['PATCH', 'finance.transactions.update', 'transaction'],
    'rename category' => ['PATCH', 'finance.categories.update', 'category'],
    'delete category' => ['DELETE', 'finance.categories.destroy', 'category'],
    'edit mapping' => ['GET', 'finance.mappings.edit', 'mapping'],
    'update mapping' => ['PUT', 'finance.mappings.update', 'mapping'],
    'delete mapping' => ['DELETE', 'finance.mappings.destroy', 'mapping'],
    'show batch' => ['GET', 'finance.batches.show', 'batch'],
    'undo batch' => ['POST', 'finance.batches.undo', 'batch'],
    'rename payee' => ['PATCH', 'finance.payees.update', 'payee'],
    'merge payee' => ['POST', 'finance.payees.merge', 'payee'],
    'delete payee' => ['DELETE', 'finance.payees.destroy', 'payee'],
    'edit rule' => ['GET', 'finance.rules.edit', 'rule'],
    'update rule' => ['PUT', 'finance.rules.update', 'rule'],
    'toggle rule' => ['PATCH', 'finance.rules.toggle', 'rule'],
    'delete rule' => ['DELETE', 'finance.rules.destroy', 'rule'],
    'review rule' => ['GET', 'finance.rules.review', 'rule'],
    'apply rule' => ['POST', 'finance.rules.apply', 'rule'],
    'transfer candidates' => ['GET', 'finance.transactions.transfer-candidates', 'transaction'],
    'pair transaction' => ['POST', 'finance.transactions.pair', 'transaction'],
    'unpair transaction' => ['DELETE', 'finance.transactions.unpair', 'transaction'],
    'mark transfer' => ['POST', 'finance.transactions.mark-transfer', 'transaction'],
    'unmark transfer' => ['DELETE', 'finance.transactions.unmark-transfer', 'transaction'],
    'confirm suggestion' => ['POST', 'finance.transfers.confirm', 'suggestion'],
    'dismiss suggestion' => ['POST', 'finance.transfers.dismiss', 'suggestion'],
    'update subscription' => ['PATCH', 'finance.subscriptions.update', 'subscription'],
    'cancel subscription' => ['POST', 'finance.subscriptions.cancel', 'subscription'],
    'confirm subscription suggestion' => ['POST', 'finance.subscriptions.confirm', 'subscription_suggestion'],
    'dismiss subscription suggestion' => ['POST', 'finance.subscriptions.dismiss', 'subscription_suggestion'],
    'link transaction to a subscription' => ['POST', 'finance.transactions.link-subscription', 'transaction'],
    'unlink transaction from a subscription' => ['DELETE', 'finance.transactions.unlink-subscription', 'transaction'],
]);

test('a transaction cannot be linked to another user\'s subscription or marked as one by them', function () {
    $owner = User::factory()->create();
    $theirs = Subscription::factory()->for($owner)->create();
    $theirTransaction = Transaction::factory()->for(Account::factory()->for($owner))->create(['amount' => -500]);
    $user = User::factory()->create();
    $mine = Transaction::factory()->for(Account::factory()->for($user))->create(['amount' => -500]);

    $this->actingAs($user)->post(route('finance.transactions.link-subscription', $mine), ['subscription_id' => $theirs->id])->assertNotFound();
    $this->post(route('finance.subscriptions.store'), ['transaction_id' => $theirTransaction->id, 'name' => 'X', 'amount' => '5', 'interval' => 'monthly'])->assertNotFound();
    $this->post(route('finance.subscriptions.store'), ['name' => 'X', 'amount' => '5', 'interval' => 'monthly', 'payee_id' => Payee::factory()->for($owner)->create()->id])->assertNotFound();

    expect($mine->fresh()->subscription_id)->toBeNull()
        ->and(Subscription::query()->count())->toBe(1);
});

test('a transaction cannot be paired with another user\'s, and suggestions stay private', function () {
    $owner = User::factory()->create();
    $theirs = Transaction::factory()->for(Account::factory()->for($owner))->create(['amount' => 500]);
    TransferSuggestion::query()->forceCreate([
        'user_id' => $owner->id,
        'outflow_id' => Transaction::factory()->for(Account::factory()->for($owner))->create(['amount' => -500])->id,
        'inflow_id' => $theirs->id,
    ]);

    $user = User::factory()->create();
    $mine = Transaction::factory()->for(Account::factory()->for($user))->create(['amount' => -500]);

    $this->actingAs($user)->post(route('finance.transactions.pair', $mine), ['counterpart_id' => $theirs->id])->assertNotFound();

    expect($mine->refresh()->is_transfer)->toBeFalse()
        ->and($theirs->refresh()->is_transfer)->toBeFalse();

    $this->get(route('finance.transfers.index'))->assertInertia(fn ($page) => $page->has('suggestions', 0));
    $this->get(route('finance.transactions.transfer-candidates', $mine))->assertJsonCount(0, 'candidates');
});

test('lists of payees and rules only show the user\'s own', function () {
    $user = User::factory()->create();
    Payee::factory()->create();
    Rule::factory()->create();

    $this->actingAs($user)->get(route('finance.payees.index'))->assertInertia(fn ($page) => $page->has('payees', 0));
    $this->get(route('finance.rules.index'))->assertInertia(fn ($page) => $page->has('rules', 0));
});

test('rules never touch another user\'s transactions', function () {
    $theirs = Transaction::factory()->create(['description' => 'Migros']);
    $user = User::factory()->create();
    $this->actingAs($user)->post(route('finance.rules.store'), [
        'conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => 'migros']],
        'actions' => [['type' => 'set_category', 'value' => $user->categories()->value('id')]],
    ]);

    $this->postJson(route('finance.rules.matches'), ['conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => 'migros']]])
        ->assertJson(['count' => 0]);
    $this->post(route('finance.rules.apply-all'));

    expect($theirs->refresh()->category_id)->toBeNull();
});

test('another user gets 404 on an upload', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create())
        ->post(route('finance.import.store'), ['file' => UploadedFile::fake()->createWithContent('a.csv', "Date,Description,Amount\n2026-10-01,A,1.00\n")]);
    $upload = basename((string) Storage::disk('local')->directories('finance-imports/'.auth()->id())[0]);

    $this->actingAs(User::factory()->create());

    $this->get(route('finance.import.show', $upload))->assertNotFound();
    $this->post(route('finance.import.commit', $upload))->assertNotFound();
});

test('lists only show the user\'s own rows', function () {
    $mine = Transaction::factory()->for(Account::factory()->for($user = User::factory()->create()))->create(['description' => 'Mine']);
    Transaction::factory()->create(['description' => 'Theirs']);

    $this->actingAs($user)->get(route('finance.transactions.index'))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 1)->where('transactions.data.0.id', $mine->id));
});

test('guests are sent to the login page', function () {
    $this->get(route('finance.accounts.index'))->assertRedirect(route('login'));
});
