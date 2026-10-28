<?php

use App\Actions\Finance\SeedDefaultCategories;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->actingAs($this->user);
});

test('new users get the default categories', function () {
    expect($this->user->categories()->orderBy('name')->pluck('name')->all())->toBe(collect(SeedDefaultCategories::NAMES)->sort()->values()->all());
});

test('a transaction is categorized by hand and can be uncategorized again', function () {
    $transaction = Transaction::factory()->for($this->account)->create();
    $groceries = $this->user->categories()->where('name', 'Groceries')->sole();

    $this->patch(route('finance.transactions.update', $transaction), ['category_id' => $groceries->id])->assertSessionHasNoErrors();
    expect($transaction->refresh()->category_id)->toBe($groceries->id);

    $this->patch(route('finance.transactions.update', $transaction), ['category_id' => null]);
    expect($transaction->refresh()->category_id)->toBeNull();
});

test('a transaction cannot get another user\'s category', function () {
    $transaction = Transaction::factory()->for($this->account)->create();

    $this->patch(route('finance.transactions.update', $transaction), ['category_id' => Category::factory()->create()->id])
        ->assertSessionHasErrors('category_id');

    expect($transaction->refresh()->category_id)->toBeNull();
});

test('the list filters by account, category, date range, uncategorized and text', function (array $query, array $expected) {
    $other = Account::factory()->for($this->user)->create();
    $dining = $this->user->categories()->where('name', 'Dining')->sole();
    Transaction::factory()->for($this->account)->create(['description' => 'COOP Basel', 'date' => '2026-10-01']);
    Transaction::factory()->for($this->account)->create(['description' => 'Pizza Napoli', 'date' => '2026-10-10', 'category_id' => $dining->id]);
    Transaction::factory()->for($other)->create(['description' => 'Coop Zürich', 'date' => '2026-10-20']);

    $query = array_map(fn (string $value): string => match ($value) {
        ':other' => (string) $other->id,
        ':dining' => (string) $dining->id,
        default => $value,
    }, $query);

    $this->get(route('finance.transactions.index', $query))
        ->assertInertia(fn ($page) => $page->where('transactions.data', fn ($rows) => collect($rows)->pluck('description')->sort()->values()->all() === $expected));
})->with([
    'account' => [['account' => ':other'], ['Coop Zürich']],
    'category' => [['category' => ':dining'], ['Pizza Napoli']],
    'uncategorized' => [['category' => 'none'], ['COOP Basel', 'Coop Zürich']],
    'date range' => [['from' => '2026-10-05', 'to' => '2026-10-10'], ['Pizza Napoli']],
    'search ignores case' => [['search' => 'coop'], ['COOP Basel', 'Coop Zürich']],
]);

test('the list is paginated', function () {
    Transaction::factory()->for($this->account)->count(51)->create();

    $this->get(route('finance.transactions.index', ['page' => 2]))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 1)->where('transactions.total', 51));
});

test('deleting a category leaves its transactions uncategorized', function () {
    $category = Category::factory()->for($this->user)->create();
    $transaction = Transaction::factory()->for($this->account)->create(['category_id' => $category->id]);

    $this->delete(route('finance.categories.destroy', $category));

    expect($transaction->refresh()->category_id)->toBeNull();
});

test('category names are unique per user', function () {
    $this->post(route('finance.categories.store'), ['name' => 'Groceries'])
        ->assertSessionHasErrors(['name' => 'You already have a category with this name.']);

    $this->post(route('finance.categories.store'), ['name' => 'Travel'])->assertSessionHasNoErrors();
    expect($this->user->categories()->where('name', 'Travel')->exists())->toBeTrue();
});
