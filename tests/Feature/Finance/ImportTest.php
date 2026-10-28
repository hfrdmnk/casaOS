<?php

use App\Models\Finance\Account;
use App\Models\Finance\AccountType;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\FieldSource;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Rule;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function financeFixture(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(base_path("tests/Fixtures/finance/{$name}")));
}

function uploadId(TestResponse $response): string
{
    preg_match('#/finance/import/([0-9a-f-]{36})$#', (string) $response->headers->get('Location'), $matches);

    return $matches[1] ?? '';
}

/**
 * @return array<string, mixed>
 */
function twoProductMapping(string $product, Account $account): array
{
    return [
        'name' => "Bank {$product}",
        'account_id' => $account->id,
        'encoding' => 'UTF-8',
        'delimiter' => ',',
        'skip_lines' => 0,
        'date_column' => 2,
        'date_format' => 'Y-m-d H:i:s',
        'description_column' => 4,
        'amount_mode' => 'signed',
        'amount_column' => 5,
        'decimal_separator' => '.',
        'fee_column' => 6,
        'currency_column' => 7,
        'status_column' => 8,
        'status_map' => ['COMPLETED' => 'completed', 'PENDING' => 'pending', 'REVERTED' => 'reverted'],
        'balance_column' => 9,
        'filter_column' => 1,
        'filter_value' => $product,
    ];
}

/**
 * Upload two-products.csv, save a mapping per product through the wizard, and import it.
 *
 * @return array{current: Account, savings: Account, batch: ImportBatch}
 */
function importTwoProducts(User $user): array
{
    $current = Account::factory()->for($user)->create(['name' => 'Current']);
    $savings = Account::factory()->for($user)->type(AccountType::Savings)->create(['name' => 'Savings']);

    $upload = uploadId(test()->post(route('finance.import.store'), ['file' => financeFixture('two-products.csv')]));

    test()->post(route('finance.mappings.store', $upload), twoProductMapping('Current', $current))->assertSessionHasNoErrors();
    test()->post(route('finance.mappings.store', $upload), twoProductMapping('Savings', $savings))->assertSessionHasNoErrors();
    test()->post(route('finance.import.commit', $upload))->assertRedirect();

    return ['current' => $current, 'savings' => $savings, 'batch' => ImportBatch::query()->latest('id')->firstOrFail()];
}

test('a file with an unknown shape opens the mapper', function () {
    $response = $this->post(route('finance.import.store'), ['file' => financeFixture('two-products.csv')]);

    $response->assertRedirect(route('finance.import.show', uploadId($response)));
    expect(ImportBatch::count())->toBe(0);

    $this->get(route('finance.import.show', uploadId($response)))
        ->assertInertia(fn ($page) => $page
            ->component('finance/import/show')
            ->where('detected', ['encoding' => 'UTF-8', 'delimiter' => ',', 'skip_lines' => 0])
            ->where('matching', []));
});

test('one file feeds two accounts through row filters and their balances match the file', function () {
    ['current' => $current, 'savings' => $savings, 'batch' => $batch] = importTwoProducts($this->user);

    // Current: 5 completed + 2 pending rows, the reverted row has nothing to remove.
    expect($current->transactions()->where('status', TransactionStatus::Completed)->count())->toBe(5)
        ->and($current->transactions()->where('status', TransactionStatus::Pending)->count())->toBe(2)
        ->and($savings->transactions()->count())->toBe(2)
        // Last Balance per product in file order: Current 166.50, Savings 450.30.
        ->and($current->balanceOn('2026-09-30'))->toBe(16650)
        ->and($savings->balanceOn('2026-09-30'))->toBe(45030)
        ->and($batch->summary)->toMatchArray(['created' => 9, 'skipped_reverted' => 1, 'unmapped_rows' => 0]);

    $abroad = $current->transactions()->where('description', 'Shop Abroad')->sole();
    expect($abroad->amount)->toBe(-2010)->and($abroad->fee)->toBe(10);

    expect($current->transactions()->where('description', 'Bäckerei 🥐')->exists())->toBeTrue();

    $snapshot = BalanceSnapshot::query()->where('account_id', $current->id)->sole();
    expect($snapshot->date)->toBe('2026-09-03')->and($snapshot->balance)->toBe(16650);
});

test('re-uploading the same file skips the mapper and creates no duplicates', function () {
    ['current' => $current] = importTwoProducts($this->user);

    $response = $this->post(route('finance.import.store'), ['file' => financeFixture('two-products.csv')]);

    $second = ImportBatch::query()->latest('id')->firstOrFail();
    $response->assertRedirect(route('finance.batches.show', $second));

    expect($second->summary)->toMatchArray(['created' => 0, 'updated' => 0, 'skipped_duplicate' => 9])
        ->and(Transaction::count())->toBe(9)
        // The two identical Coffee Bar rows are both kept, once each.
        ->and($current->transactions()->where('description', 'Coffee Bar')->count())->toBe(2);
});

test('an overlapping later file completes pending rows in place, removes reverted ones and adds new rows', function () {
    ['current' => $current, 'savings' => $savings] = importTwoProducts($this->user);
    $kiosk = $current->transactions()->where('description', 'Kiosk')->sole();

    $this->post(route('finance.import.store'), ['file' => financeFixture('two-products-later.csv')]);

    $batch = ImportBatch::query()->latest('id')->firstOrFail();
    expect($batch->summary)->toMatchArray(['created' => 2, 'updated' => 1, 'removed_reverted' => 1, 'skipped_duplicate' => 3]);

    $kiosk->refresh();
    expect($kiosk->status)->toBe(TransactionStatus::Completed)
        ->and($kiosk->amount)->toBe(-505)
        ->and($kiosk->fee)->toBe(5)
        ->and($current->transactions()->where('description', 'Train ticket')->exists())->toBeFalse()
        // Last Balance in the later file: Current 143.00, Savings 451.30.
        ->and($current->balanceOn('2026-09-30'))->toBe(14300)
        ->and($savings->balanceOn('2026-09-30'))->toBe(45130)
        ->and(collect($current->reconciliation())->pluck('difference')->all())->toBe([null, 0]);
});

test('undoing a batch restores what it updated and removed', function () {
    ['current' => $current] = importTwoProducts($this->user);
    $this->post(route('finance.import.store'), ['file' => financeFixture('two-products-later.csv')]);
    $later = ImportBatch::query()->latest('id')->firstOrFail();

    $this->post(route('finance.batches.undo', $later))->assertRedirect(route('finance.batches.show', $later));

    expect($later->refresh()->undone_at)->not->toBeNull()
        ->and($current->transactions()->where('description', 'Kiosk')->sole()->only(['amount', 'status']))
        ->toBe(['amount' => -495, 'status' => TransactionStatus::Pending])
        ->and($current->transactions()->where('description', 'Train ticket')->exists())->toBeTrue()
        ->and($current->transactions()->where('description', 'Lunch')->exists())->toBeFalse()
        ->and($current->balanceOn('2026-09-30'))->toBe(16650);
});

test('an earlier batch cannot be undone while a later one on the same account is active', function () {
    ['batch' => $first] = importTwoProducts($this->user);
    $this->post(route('finance.import.store'), ['file' => financeFixture('two-products-later.csv')]);

    $this->post(route('finance.batches.undo', $first));

    expect($first->refresh()->undone_at)->toBeNull()->and(Transaction::count())->toBe(10);
});

test('undoing the only batch removes its transactions and snapshots', function () {
    ['batch' => $batch] = importTwoProducts($this->user);

    $this->post(route('finance.batches.undo', $batch));

    expect(Transaction::count())->toBe(0)->and(BalanceSnapshot::count())->toBe(0);
});

test('a file with a row that fails to parse is rejected whole with the row errors', function () {
    $account = Account::factory()->for($this->user)->create();
    ImportMapping::factory()->for($account)->create();

    $response = $this->post(route('finance.import.store'), ['file' => financeFixture('broken.csv')]);

    $response->assertRedirect(route('finance.import.show', uploadId($response)))
        ->assertSessionHas('import_rejection', fn (array $rejection): bool => $rejection['message'] === '2 of 3 rows could not be read. Nothing was imported.'
            && array_column($rejection['errors'], 'line') === [3, 4]);

    expect(Transaction::count())->toBe(0)->and(ImportBatch::count())->toBe(0);
});

test('a semicolon file with a preamble and debit and credit columns imports and skips other currencies', function () {
    $account = Account::factory()->for($this->user)->create();
    $upload = uploadId($this->post(route('finance.import.store'), ['file' => financeFixture('debit-credit.csv')]));

    $this->get(route('finance.import.show', $upload))
        ->assertInertia(fn ($page) => $page->where('detected', ['encoding' => 'UTF-8', 'delimiter' => ';', 'skip_lines' => 4]));

    $this->post(route('finance.mappings.store', $upload), [
        'name' => 'Privatkonto',
        'account_id' => $account->id,
        'encoding' => 'UTF-8',
        'delimiter' => ';',
        'skip_lines' => 4,
        'date_column' => 0,
        'date_format' => 'd.m.Y',
        'description_column' => 1,
        'amount_mode' => 'split',
        'debit_column' => 2,
        'credit_column' => 3,
        'decimal_separator' => ',',
        'thousands_separator' => "'",
        'currency_column' => 4,
        'balance_column' => 5,
    ])->assertSessionHasNoErrors();
    $this->post(route('finance.import.commit', $upload));

    $batch = ImportBatch::query()->latest('id')->firstOrFail();
    expect($batch->summary['created'])->toBe(3)
        ->and($batch->summary['skipped_currency'])->toBe(1)
        ->and($batch->summary['currency_rows'][0])->toMatchArray(['line' => 8, 'description' => 'Hotel Wien', 'currency' => 'EUR'])
        ->and($account->transactions()->orderBy('date')->pluck('amount')->all())->toBe([520000, -185000, -6435])
        ->and($account->balanceOn('2026-10-31'))->toBe(428565);
});

test('the preview reads every row through the mapping and reports failures', function () {
    $upload = uploadId($this->post(route('finance.import.store'), ['file' => financeFixture('broken.csv')]));

    $this->postJson(route('finance.import.preview', $upload), [
        'parse' => true,
        'encoding' => 'UTF-8',
        'delimiter' => ',',
        'skip_lines' => 0,
        'date_column' => 0,
        'date_format' => 'Y-m-d',
        'description_column' => 1,
        'amount_mode' => 'signed',
        'amount_column' => 2,
        'decimal_separator' => '.',
    ])->assertOk()
        ->assertJsonPath('header', ['Date', 'Description', 'Amount'])
        ->assertJsonPath('parsed.selected', 3)
        ->assertJsonPath('parsed.failed', 2)
        ->assertJsonPath('parsed.rows.0.amount', 1000);
});

test('a mapping cannot target a balance-only account', function () {
    $investment = Account::factory()->for($this->user)->type(AccountType::Investment)->create();
    $upload = uploadId($this->post(route('finance.import.store'), ['file' => financeFixture('simple.csv')]));

    $this->post(route('finance.mappings.store', $upload), [
        ...ImportMapping::factory()->make()->only(['encoding', 'delimiter', 'skip_lines', 'date_column', 'date_format', 'description_column', 'amount_column', 'decimal_separator']),
        'amount_mode' => 'signed',
        'name' => 'Simple',
        'account_id' => $investment->id,
    ])->assertSessionHasErrors(['account_id' => 'Choose one of your active checking, savings or credit card accounts.']);

    expect(ImportMapping::count())->toBe(0);
});

test('editing a mapping keeps it matching the file shape', function () {
    $account = Account::factory()->for($this->user)->create();
    $mapping = ImportMapping::factory()->for($account)->create();

    $this->put(route('finance.mappings.update', $mapping), [
        ...$mapping->only(['encoding', 'delimiter', 'skip_lines', 'date_column', 'date_format', 'description_column', 'amount_column', 'decimal_separator']),
        'amount_mode' => 'signed',
        'name' => 'Renamed',
        'account_id' => $account->id,
        'invert_sign' => true,
    ])->assertRedirect(route('finance.import.index'));

    $this->post(route('finance.import.store'), ['file' => financeFixture('simple.csv')]);

    expect($mapping->refresh()->name)->toBe('Renamed')
        ->and($account->transactions()->orderBy('date')->pluck('amount')->all())->toBe([-100000, 8000, 50000]);
});

test('rules categorize created rows and rule-owned fields of rows completed in place, and undo restores them', function (bool $setByHand) {
    $dining = $this->user->categories()->where('name', 'Dining')->sole();
    $shopping = $this->user->categories()->where('name', 'Shopping')->sole();
    $groceries = $this->user->categories()->where('name', 'Groceries')->sole();
    $this->post(route('finance.rules.store'), [
        'conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => 'kiosk']],
        'actions' => [['type' => 'set_category', 'value' => $dining->id], ['type' => 'set_payee', 'name' => 'Kiosk']],
    ])->assertSessionHasNoErrors();
    $rule = Rule::query()->sole();

    $first = importTwoProducts($this->user)['batch'];
    $kiosk = Transaction::query()->where('description', 'Kiosk')->sole();

    expect($first->summary['categorized_by_rules'])->toBe(1)
        ->and($kiosk)->category_id->toBe($dining->id)->category_source->toBe(FieldSource::Rule)
        ->and($kiosk->payee?->name)->toBe('Kiosk');

    if ($setByHand) {
        $this->patch(route('finance.transactions.update', $kiosk), ['category_id' => $groceries->id]);
    }

    $this->put(route('finance.rules.update', $rule), [
        'conditions' => $rule->conditions,
        'actions' => [['type' => 'set_category', 'value' => $shopping->id], $rule->actions[1]],
    ])->assertSessionHasNoErrors();

    $this->post(route('finance.import.store'), ['file' => financeFixture('two-products-later.csv')]);
    $later = ImportBatch::query()->latest('id')->firstOrFail();

    expect($kiosk->refresh())
        ->status->toBe(TransactionStatus::Completed)
        ->category_id->toBe($setByHand ? $groceries->id : $shopping->id)
        ->and(Transaction::query()->where('description', 'Lunch')->sole()->category_id)->toBeNull();

    $this->post(route('finance.batches.undo', $later));

    expect($kiosk->refresh())
        ->status->toBe(TransactionStatus::Pending)
        ->category_id->toBe($setByHand ? $groceries->id : $dining->id)
        ->category_source->toBe($setByHand ? FieldSource::Manual : FieldSource::Rule);
})->with(['rule-owned' => false, 'set by hand' => true]);

test('undo restores a reverted row with its payee', function () {
    importTwoProducts($this->user);
    $train = Transaction::query()->where('description', 'Train ticket')->sole();
    $payee = $this->user->payees()->create(['name' => 'SBB']);
    $this->patch(route('finance.transactions.update', $train), ['payee_id' => $payee->id]);

    $this->post(route('finance.import.store'), ['file' => financeFixture('two-products-later.csv')]);
    expect(Transaction::query()->whereKey($train->id)->exists())->toBeFalse();

    $this->post(route('finance.batches.undo', ImportBatch::query()->latest('id')->firstOrFail()));

    expect($train->refresh())->payee_id->toBe($payee->id)->payee_source->toBe(FieldSource::Manual);
});
