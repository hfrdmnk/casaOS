<?php

use App\Models\Finance\Account;
use App\Models\Finance\FieldSource;
use App\Models\Finance\Payee;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

test('payees are created and renamed with unique names', function () {
    $this->post(route('finance.payees.store'), ['name' => ' Coop '])->assertSessionHasNoErrors();
    $coop = $this->user->payees()->sole();

    expect($coop->name)->toBe('Coop');

    $this->post(route('finance.payees.store'), ['name' => 'Coop'])->assertSessionHasErrors(['name' => 'You already have a payee with this name.']);
    $this->patch(route('finance.payees.update', $coop), ['name' => 'Coop Group'])->assertSessionHasNoErrors();

    expect($coop->refresh()->name)->toBe('Coop Group');
});

test('merging moves transactions and rules to the other payee', function () {
    $pronto = Payee::factory()->for($this->user)->create(['name' => 'Coop Pronto']);
    $coop = Payee::factory()->for($this->user)->create(['name' => 'Coop']);
    $transaction = Transaction::factory()->for($this->account)->create(['payee_id' => $pronto->id, 'payee_source' => FieldSource::Manual]);
    $untouched = Transaction::factory()->for($this->account)->create();
    $rule = Rule::factory()->for($this->user)->create([
        'conditions' => [['field' => 'payee', 'operator' => 'is', 'value' => $pronto->id]],
        'actions' => [['type' => 'set_payee', 'value' => $pronto->id]],
    ]);

    $this->post(route('finance.payees.merge', $pronto), ['into' => $coop->id])->assertSessionHasNoErrors();

    expect(Payee::query()->whereKey($pronto->id)->exists())->toBeFalse()
        ->and($transaction->refresh())->payee_id->toBe($coop->id)->payee_source->toBe(FieldSource::Manual)
        ->and($untouched->refresh()->payee_id)->toBeNull()
        ->and($rule->refresh()->conditions[0]['value'])->toBe($coop->id)
        ->and($rule->actions[0]['value'])->toBe($coop->id);
});

test('a payee cannot be merged into itself or another user\'s payee', function () {
    $coop = Payee::factory()->for($this->user)->create();

    $this->post(route('finance.payees.merge', $coop), ['into' => $coop->id])->assertSessionHasErrors('into');
    $this->post(route('finance.payees.merge', $coop), ['into' => Payee::factory()->create()->id])->assertNotFound();

    expect(Payee::query()->whereKey($coop->id)->exists())->toBeTrue();
});

test('deleting a payee leaves its transactions payee-less and its rules without the action', function () {
    $coop = Payee::factory()->for($this->user)->create();
    $transaction = Transaction::factory()->for($this->account)->create(['payee_id' => $coop->id, 'payee_source' => FieldSource::Rule]);
    $setsIt = Rule::factory()->for($this->user)->create(['actions' => [['type' => 'set_payee', 'value' => $coop->id]]]);
    $needsIt = Rule::factory()->for($this->user)->create([
        'conditions' => [['field' => 'payee', 'operator' => 'is', 'value' => $coop->id]],
        'actions' => [['type' => 'set_category', 'value' => $this->user->categories()->value('id')]],
    ]);

    $this->delete(route('finance.payees.destroy', $coop));

    expect($transaction->refresh())->payee_id->toBeNull()->payee_source->toBeNull()
        ->and($setsIt->refresh())->actions->toBe([])->enabled->toBeFalse()
        ->and($needsIt->refresh()->enabled)->toBeFalse();
});

test('the list shows the payee and search matches the payee or the raw description', function () {
    $coop = Payee::factory()->for($this->user)->create(['name' => 'Coop']);
    Transaction::factory()->for($this->account)->create(['description' => 'CRV*PRONTO 4711', 'payee_id' => $coop->id]);
    Transaction::factory()->for($this->account)->create(['description' => 'Coop Restaurant']);
    Transaction::factory()->for($this->account)->create(['description' => 'Migros']);

    $this->get(route('finance.transactions.index', ['search' => 'coop']))
        ->assertInertia(fn ($page) => $page->where('transactions.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['description'], $row['payee']])->sortBy(0)->values()->all() === [
            ['CRV*PRONTO 4711', 'Coop'],
            ['Coop Restaurant', null],
        ]));
});
