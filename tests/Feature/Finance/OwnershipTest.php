<?php

use App\Models\Finance\Account;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\Category;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Transaction;
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
    ];

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
]);

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
