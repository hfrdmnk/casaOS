<?php

use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\FieldSource;
use App\Models\Finance\Payee;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['name' => 'Current']);
    $this->actingAs($this->user);
});

function category(string $name): Category
{
    return test()->user->categories()->where('name', $name)->sole();
}

/**
 * @param  list<array<string, mixed>>  $conditions
 * @param  list<array<string, mixed>>  $actions
 */
function createRule(array $conditions, array $actions, ?string $name = null): Rule
{
    test()->post(route('finance.rules.store'), ['name' => $name, 'conditions' => $conditions, 'actions' => $actions])->assertSessionHasNoErrors();

    return Rule::query()->latest('id')->firstOrFail();
}

test('conditions match descriptions, amounts, accounts and payees', function (array $condition, array $expected) {
    $other = Account::factory()->for($this->user)->create(['name' => 'Savings']);
    $coop = Payee::factory()->for($this->user)->create(['name' => 'Coop']);
    Transaction::factory()->for($this->account)->create(['description' => 'COOP  Pronto Basel', 'amount' => -1250, 'payee_id' => $coop->id]);
    Transaction::factory()->for($other)->create(['description' => 'Coop Restaurant', 'amount' => -3000]);
    Transaction::factory()->for($this->account)->create(['description' => 'Migros', 'amount' => 500]);
    Transaction::factory()->for($this->account)->create(['description' => 'SBB CFF FFS', 'amount' => -2200]);

    $condition['value'] = match ($condition['value']) {
        ':savings' => $other->id,
        ':coop' => $coop->id,
        default => $condition['value'],
    };

    $response = $this->postJson(route('finance.rules.matches'), ['conditions' => [$condition]])->assertOk();

    expect(collect($response->json('sample'))->pluck('description')->sort()->values()->all())->toBe($expected)
        ->and($response->json('count'))->toBe(count($expected));
})->with([
    'contains ignores case and extra spaces' => [['field' => 'description', 'operator' => 'contains', 'value' => 'coop pronto'], ['COOP  Pronto Basel']],
    'equals is the whole description' => [['field' => 'description', 'operator' => 'equals', 'value' => 'MIGROS'], ['Migros']],
    'equals does not match a prefix' => [['field' => 'description', 'operator' => 'equals', 'value' => 'migr'], []],
    'starts with' => [['field' => 'description', 'operator' => 'starts_with', 'value' => 'coop'], ['COOP  Pronto Basel', 'Coop Restaurant']],
    'regex is case-insensitive' => [['field' => 'description', 'operator' => 'regex', 'value' => '^sbb\b|^migros$'], ['Migros', 'SBB CFF FFS']],
    'amount below a negative bound' => [['field' => 'amount', 'operator' => 'less_than', 'value' => -2000], ['Coop Restaurant', 'SBB CFF FFS']],
    'amount above zero is income' => [['field' => 'amount', 'operator' => 'greater_than', 'value' => 0], ['Migros']],
    'signed amount above 20 matches no spending' => [['field' => 'amount', 'operator' => 'greater_than', 'value' => 2000], []],
    'absolute amount ignores the sign' => [['field' => 'absolute_amount', 'operator' => 'greater_than', 'value' => 2000], ['Coop Restaurant', 'SBB CFF FFS']],
    'between includes both bounds' => [['field' => 'amount', 'operator' => 'between', 'value' => [-2200, -1250]], ['COOP  Pronto Basel', 'SBB CFF FFS']],
    'absolute amount equals' => [['field' => 'absolute_amount', 'operator' => 'equals', 'value' => 500], ['Migros']],
    'signed amount equals keeps the sign' => [['field' => 'amount', 'operator' => 'equals', 'value' => -500], []],
    'account is' => [['field' => 'account', 'operator' => 'is', 'value' => ':savings'], ['Coop Restaurant']],
    'payee is' => [['field' => 'payee', 'operator' => 'is', 'value' => ':coop'], ['COOP  Pronto Basel']],
]);

test('all matching rules apply in position order and a later rule wins the same field', function () {
    $pronto = Transaction::factory()->for($this->account)->create(['description' => 'Coop Pronto']);
    $restaurant = Transaction::factory()->for($this->account)->create(['description' => 'Coop Restaurant']);
    $coop = createRule([['field' => 'description', 'operator' => 'starts_with', 'value' => 'coop']], [
        ['type' => 'set_category', 'value' => category('Groceries')->id],
        ['type' => 'set_payee', 'name' => 'Coop'],
    ]);
    $dining = createRule([['field' => 'description', 'operator' => 'contains', 'value' => 'restaurant']], [['type' => 'set_category', 'value' => category('Dining')->id]]);

    $this->post(route('finance.rules.apply-all'))->assertRedirect(route('finance.rules.index'));

    expect($restaurant->refresh()->category?->name)->toBe('Dining')
        ->and($restaurant->payee?->name)->toBe('Coop')
        ->and($pronto->refresh()->category?->name)->toBe('Groceries');

    $this->put(route('finance.rules.reorder'), ['order' => [$dining->id, $coop->id]])->assertSessionHasNoErrors();
    $this->post(route('finance.rules.apply-all'));

    expect($restaurant->refresh()->category?->name)->toBe('Groceries');
});

test('a condition sees the payee an earlier rule set in the same run', function () {
    $transaction = Transaction::factory()->for($this->account)->create(['description' => 'Coop Pronto 1234']);
    $coop = Payee::factory()->for($this->user)->create(['name' => 'Coop']);
    createRule([['field' => 'description', 'operator' => 'contains', 'value' => 'pronto']], [['type' => 'set_payee', 'value' => $coop->id]]);
    createRule([['field' => 'payee', 'operator' => 'is', 'value' => $coop->id]], [['type' => 'set_category', 'value' => category('Groceries')->id]]);

    $this->post(route('finance.rules.apply-all'));

    expect($transaction->refresh()->payee_id)->toBe($coop->id)
        ->and($transaction->category?->name)->toBe('Groceries');
});

test('disabled rules do not apply', function () {
    $transaction = Transaction::factory()->for($this->account)->create(['description' => 'Migros']);
    $rule = createRule([['field' => 'description', 'operator' => 'contains', 'value' => 'migros']], [['type' => 'set_category', 'value' => category('Groceries')->id]]);

    $this->patch(route('finance.rules.toggle', $rule), ['enabled' => false]);
    $this->post(route('finance.rules.apply-all'));

    expect($transaction->refresh()->category_id)->toBeNull();
});

test('values set by hand survive re-applying and clearing them hands the field back to the rules', function () {
    $transaction = Transaction::factory()->for($this->account)->create(['description' => 'Migros']);
    $neighbour = Transaction::factory()->for($this->account)->create(['description' => 'Migros Basel']);
    $migros = Payee::factory()->for($this->user)->create(['name' => 'Migros']);
    $mine = Payee::factory()->for($this->user)->create(['name' => 'Mine']);
    createRule([['field' => 'description', 'operator' => 'contains', 'value' => 'migros']], [
        ['type' => 'set_category', 'value' => category('Groceries')->id],
        ['type' => 'set_payee', 'value' => $migros->id],
    ]);

    $this->patch(route('finance.transactions.update', $transaction), ['category_id' => category('Shopping')->id]);
    $this->patch(route('finance.transactions.update', $transaction), ['payee_id' => $mine->id]);
    $this->post(route('finance.rules.apply-all'));

    expect($transaction->refresh())
        ->category_id->toBe(category('Shopping')->id)
        ->category_source->toBe(FieldSource::Manual)
        ->payee_id->toBe($mine->id)
        ->payee_source->toBe(FieldSource::Manual)
        ->and($neighbour->refresh()->category_id)->toBe(category('Groceries')->id)
        ->and($neighbour->category_source)->toBe(FieldSource::Rule);

    $this->patch(route('finance.transactions.update', $transaction), ['category_id' => null]);

    expect($transaction->refresh())
        ->category_id->toBe(category('Groceries')->id)
        ->category_source->toBe(FieldSource::Rule)
        ->payee_id->toBe($mine->id);
});

test('the dry run reports the changes without writing and apply writes them', function () {
    $migros = Transaction::factory()->for($this->account)->create(['description' => 'Migros', 'category_id' => category('Shopping')->id, 'category_source' => FieldSource::Rule]);
    $coop = Transaction::factory()->for($this->account)->create(['description' => 'Coop']);
    Transaction::factory()->for($this->account)->create(['description' => 'Hetzner']);
    $rule = createRule([['field' => 'description', 'operator' => 'regex', 'value' => '^(migros|coop)$']], [['type' => 'set_category', 'value' => category('Groceries')->id]]);
    createRule([['field' => 'description', 'operator' => 'equals', 'value' => 'hetzner']], [['type' => 'set_payee', 'name' => 'Hetzner']]);

    $this->get(route('finance.rules.review', $rule))->assertInertia(fn ($page) => $page
        ->component('finance/rules/apply')
        ->where('transactions', 2)
        ->where('changes', ['category' => 2, 'payee' => 0, 'transfer' => 0])
        ->where('sample', fn ($sample) => collect($sample)->mapWithKeys(fn ($entry) => [$entry['transaction']['description'] => $entry['changes'][0]])->sortKeys()->all() === [
            'Coop' => ['field' => 'category', 'from' => null, 'to' => 'Groceries'],
            'Migros' => ['field' => 'category', 'from' => 'Shopping', 'to' => 'Groceries'],
        ]));

    $this->get(route('finance.rules.review-all'))->assertInertia(fn ($page) => $page->where('transactions', 3)->where('changes', ['category' => 2, 'payee' => 1, 'transfer' => 0]));

    expect($migros->refresh()->category?->name)->toBe('Shopping')
        ->and($coop->refresh()->category_id)->toBeNull();

    $this->post(route('finance.rules.apply', $rule));

    expect($migros->refresh()->category?->name)->toBe('Groceries')
        ->and($coop->refresh()->category?->name)->toBe('Groceries')
        ->and(Transaction::query()->whereNotNull('payee_id')->count())->toBe(0);

    $this->get(route('finance.rules.review-all'))->assertInertia(fn ($page) => $page->where('transactions', 1)->where('changes', ['category' => 0, 'payee' => 1, 'transfer' => 0]));
});

test('invalid rules are rejected', function (array $input, string $error) {
    $input['conditions'] ??= [['field' => 'description', 'operator' => 'contains', 'value' => 'coop']];
    $input['actions'] ??= [['type' => 'set_category', 'value' => category('Groceries')->id]];
    $input = json_decode(str_replace('":foreign"', (string) Category::factory()->create()->id, (string) json_encode($input)), true);

    $this->post(route('finance.rules.store'), $input)->assertSessionHasErrors($error);

    expect(Rule::query()->count())->toBe(0);
})->with([
    'regex that does not compile' => [['conditions' => [['field' => 'description', 'operator' => 'regex', 'value' => 'coop(']]], 'conditions.0.value'],
    'operator of another field' => [['conditions' => [['field' => 'amount', 'operator' => 'contains', 'value' => 100]]], 'conditions.0.value'],
    'between in the wrong order' => [['conditions' => [['field' => 'amount', 'operator' => 'between', 'value' => [500, 100]]]], 'conditions.0.value'],
    'amount that is not a number' => [['conditions' => [['field' => 'amount', 'operator' => 'equals', 'value' => '12.50']]], 'conditions.0.value'],
    'empty description' => [['conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => ' ']]], 'conditions.0.value'],
    'no conditions' => [['conditions' => []], 'conditions'],
    'no actions' => [['actions' => []], 'actions'],
    'another user\'s category' => [['actions' => [['type' => 'set_category', 'value' => ':foreign']]], 'actions.0.value'],
    'the same field twice' => [['actions' => [['type' => 'set_payee', 'name' => 'A'], ['type' => 'set_payee', 'name' => 'B']]], 'actions.1.type'],
    'a category by name' => [['actions' => [['type' => 'set_category', 'name' => 'Groceries']]], 'actions.0.value'],
]);

test('a set payee action given by name reuses or creates the payee', function () {
    $existing = Payee::factory()->for($this->user)->create(['name' => 'Coop']);

    $first = createRule([['field' => 'description', 'operator' => 'contains', 'value' => 'coop']], [['type' => 'set_payee', 'name' => 'Coop']]);
    $second = createRule([['field' => 'description', 'operator' => 'contains', 'value' => 'hetzner']], [['type' => 'set_payee', 'name' => 'Hetzner']]);

    expect($first->actions)->toBe([['type' => 'set_payee', 'value' => $existing->id]])
        ->and($second->actions[0]['value'])->toBe($this->user->payees()->where('name', 'Hetzner')->sole()->id);
});

test('creating a rule from a transaction pre-fills its raw description', function () {
    $transaction = Transaction::factory()->for($this->account)->create(['description' => 'Coop  Pronto  4711']);

    $this->get(route('finance.rules.create', ['transaction' => $transaction->id]))
        ->assertInertia(fn ($page) => $page->component('finance/rules/edit')
            ->where('initial.conditions.0', ['field' => 'description', 'operator' => 'contains', 'value' => 'Coop Pronto 4711']));

    $this->get(route('finance.rules.create', ['transaction' => Transaction::factory()->create()->id]))->assertNotFound();
});

test('reordering must list every rule exactly once', function () {
    $first = Rule::factory()->for($this->user)->create();
    $second = Rule::factory()->for($this->user)->create();
    $foreign = Rule::factory()->create();

    $this->put(route('finance.rules.reorder'), ['order' => [$second->id, $foreign->id]])->assertSessionHasErrors('order');
    $this->put(route('finance.rules.reorder'), ['order' => [$second->id, $first->id]])->assertSessionHasNoErrors();

    expect(Rule::query()->ownedBy($this->user)->orderBy('position')->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('deleting a category removes it from rules and disables rules left without actions', function () {
    $groceries = category('Groceries');
    $onlyCategory = createRule([['field' => 'description', 'operator' => 'contains', 'value' => 'migros']], [['type' => 'set_category', 'value' => $groceries->id]]);
    $withPayee = createRule([['field' => 'description', 'operator' => 'contains', 'value' => 'coop']], [['type' => 'set_category', 'value' => $groceries->id], ['type' => 'set_payee', 'name' => 'Coop']]);
    $transaction = Transaction::factory()->for($this->account)->create(['description' => 'Coop']);
    $this->post(route('finance.rules.apply-all'));

    $this->delete(route('finance.categories.destroy', $groceries));

    expect($onlyCategory->refresh())->actions->toBe([])->enabled->toBeFalse()
        ->and($withPayee->refresh())->actions->toHaveCount(1)->enabled->toBeTrue()
        ->and($transaction->refresh())->category_id->toBeNull()->category_source->toBeNull();

    $this->post(route('finance.rules.apply-all'))->assertSessionHasNoErrors();
});
