<?php

use App\Models\Finance\Account;
use App\Models\Finance\AccountType;
use App\Models\Finance\AmountMode;
use App\Models\Finance\FieldSource;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\Finance\TransferSource;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->current = Account::factory()->for($this->user)->create(['name' => 'Current']);
    $this->savings = Account::factory()->for($this->user)->type(AccountType::Savings)->create(['name' => 'Savings']);
    $this->actingAs($this->user);

    foreach (['Current' => $this->current, 'Savings' => $this->savings] as $product => $account) {
        ImportMapping::factory()->for($account)->create([
            'name' => "Bank {$product}",
            'header' => ['Type', 'Product', 'Started Date', 'Completed Date', 'Description', 'Amount', 'Fee', 'Currency', 'State', 'Balance'],
            'date_column' => 2,
            'date_format' => 'Y-m-d H:i:s',
            'description_column' => 4,
            'amount_mode' => AmountMode::Signed,
            'amount_column' => 5,
            'fee_column' => 6,
            'currency_column' => 7,
            'status_column' => 8,
            'status_map' => ['COMPLETED' => 'completed', 'PENDING' => 'pending', 'REVERTED' => 'reverted'],
            'balance_column' => 9,
            'filter_column' => 1,
            'filter_value' => $product,
        ]);
    }
});

/**
 * Upload a fixture through the Import page; the saved mappings import it at once.
 */
function importTransfers(string $fixture): ImportBatch
{
    $file = UploadedFile::fake()->createWithContent($fixture, (string) file_get_contents(base_path("tests/Fixtures/finance/{$fixture}")));
    test()->post(route('finance.import.store'), ['file' => $file])->assertRedirect();

    return ImportBatch::query()->latest('id')->firstOrFail();
}

function row(Account $account, string $date, int $amount, int $nth = 0): Transaction
{
    return $account->transactions()->where('date', substr($date, 0, 10))->where('time', substr($date, 11))->where('amount', $amount)->orderBy('id')->skip($nth)->firstOrFail();
}

test('an import pairs transfers by closest timestamp and leaves ties as suggestions', function () {
    $batch = importTransfers('transfers.csv');

    expect($batch->summary)->toMatchArray(['created' => 15, 'paired_as_transfers' => 4, 'transfer_suggestions' => 2]);

    $paired = Transaction::query()->whereNotNull('transfer_counterpart_id')->with('transferCounterpart')->get();

    // Three 0.50 pairs on the same day and the 20.00 withdrawal: each side's counterpart has the
    // same started timestamp, the opposite amount and the other account.
    expect($paired)->toHaveCount(8)
        ->and($paired->every(fn (Transaction $transaction): bool => $transaction->transferCounterpart->transfer_counterpart_id === $transaction->id
            && $transaction->transferCounterpart->date === $transaction->date
            && $transaction->transferCounterpart->time === $transaction->time
            && $transaction->transferCounterpart->amount === -$transaction->amount
            && $transaction->transferCounterpart->account_id !== $transaction->account_id
            && $transaction->is_transfer
            && $transaction->transfer_source === TransferSource::Auto))->toBeTrue()
        ->and(row($this->current, '2026-10-01 12:30:00', -50)->transferCounterpart->is(row($this->savings, '2026-10-01 12:30:00', 50)))->toBeTrue();

    // The Kiosk payment a day later has the same amount but loses to the exact matches.
    expect(row($this->current, '2026-10-02 08:00:00', -50)->is_transfer)->toBeFalse()
        // The pending row does not pair; its completed counterpart stays plain and unsuggested.
        ->and(row($this->current, '2026-10-08 12:00:00', -3000)->is_transfer)->toBeFalse()
        ->and(row($this->savings, '2026-10-08 12:00:00', 3000)->is_transfer)->toBeFalse();

    // One -5.00 against two identical +5.00: two suggestions, nothing paired.
    $roundUp = row($this->current, '2026-10-06 10:00:00', -500);
    expect($roundUp->is_transfer)->toBeFalse()
        ->and(TransferSuggestion::query()->where('outflow_id', $roundUp->id)->pluck('inflow_id')->sort()->values()->all())
        ->toBe([row($this->savings, '2026-10-06 10:00:00', 500)->id, row($this->savings, '2026-10-06 10:00:00', 500, 1)->id]);

    // Transfers are neither income nor spending: everything else sums to
    // -0.50 - 5.00 + 5.00 + 5.00 - 30.00 + 30.00 + 300.00.
    expect((int) Transaction::query()->countsAsIncomeOrSpending()->sum('amount'))->toBe(30450);
});

test('the transaction list marks transfers and filters them', function () {
    importTransfers('transfers.csv');

    $this->get(route('finance.transactions.index', ['transfer' => 'yes']))->assertInertia(fn ($page) => $page
        ->where('transactions.total', 8)
        ->where('suggestions_count', 2)
        ->where('transactions.data.0.is_transfer', true)
        ->where('transactions.data.0.counterpart.account', 'Current'));

    $this->get(route('finance.transactions.index', ['transfer' => 'no']))->assertInertia(fn ($page) => $page->where('transactions.total', 7));

    $this->get(route('finance.transactions.index', ['transfer' => 'suggested']))->assertInertia(fn ($page) => $page
        ->where('transactions.total', 3)
        ->where('transactions.data.0.suggested_transfer', true));
});

test('confirming a suggestion pairs it by hand and drops the competing one', function () {
    importTransfers('transfers.csv');
    $suggestion = TransferSuggestion::query()->orderBy('id')->firstOrFail();

    $this->get(route('finance.transfers.index'))->assertInertia(fn ($page) => $page
        ->component('finance/transfers/index')
        ->has('suggestions', 2));

    $this->post(route('finance.transfers.confirm', $suggestion))->assertSessionHasNoErrors();

    $outflow = $suggestion->outflow->refresh();
    expect($outflow->transfer_counterpart_id)->toBe($suggestion->inflow_id)
        ->and($outflow->transfer_source)->toBe(TransferSource::Manual)
        ->and($suggestion->inflow->refresh()->transfer_source)->toBe(TransferSource::Manual);

    $this->post(route('finance.transfers.find'));

    $this->get(route('finance.transfers.index'))->assertInertia(fn ($page) => $page->has('suggestions', 0)->where('paired', 5));
});

test('a dismissed suggestion stays dismissed, and dismissing one side of a tie lets the other pair', function () {
    importTransfers('transfers.csv');
    [$dismissed, $other] = TransferSuggestion::query()->orderBy('id')->get()->all();

    $this->post(route('finance.transfers.dismiss', $dismissed))->assertSessionHasNoErrors();
    $this->post(route('finance.transfers.find'));

    expect($dismissed->refresh()->dismissed_at)->not->toBeNull()
        ->and($other->outflow->refresh()->transfer_counterpart_id)->toBe($other->inflow_id)
        ->and($other->outflow->transfer_source)->toBe(TransferSource::Auto)
        ->and($dismissed->inflow->refresh()->is_transfer)->toBeFalse();

    // Even once the -5.00 is free again, the dismissed pair is neither paired nor suggested.
    $this->delete(route('finance.transactions.unmark-transfer', $other->inflow))->assertSessionHasNoErrors();
    $this->post(route('finance.transfers.find'));

    expect($dismissed->outflow->refresh()->is_transfer)->toBeFalse()
        ->and(TransferSuggestion::query()->whereNull('dismissed_at')->count())->toBe(0);
});

test('a pending transfer pairs once it completes in place, and undoing that import unpairs it', function () {
    importTransfers('transfers.csv');
    $savingsSide = row($this->savings, '2026-10-08 12:00:00', 3000);
    $other = $this->user->categories()->where('name', 'Other')->sole();
    $this->patch(route('finance.transactions.update', $savingsSide), ['category_id' => $other->id]);

    $later = importTransfers('transfers-later.csv');

    $pending = row($this->current, '2026-10-08 12:00:00', -3000);
    expect($later->summary)->toMatchArray(['updated' => 1, 'paired_as_transfers' => 1])
        ->and($pending->status)->toBe(TransactionStatus::Completed)
        ->and($pending->transfer_counterpart_id)->toBe($savingsSide->id)
        ->and($savingsSide->refresh()->only(['is_transfer', 'transfer_counterpart_id', 'category_id']))
        ->toBe(['is_transfer' => true, 'transfer_counterpart_id' => $pending->id, 'category_id' => null]);

    $this->post(route('finance.batches.undo', $later));

    expect($pending->refresh()->only(['status', 'is_transfer', 'transfer_counterpart_id']))
        ->toBe(['status' => TransactionStatus::Pending, 'is_transfer' => false, 'transfer_counterpart_id' => null])
        // Pairing made the survivor a transfer, so it is plain again with its category back.
        ->and($savingsSide->refresh()->only(['is_transfer', 'transfer_source', 'transfer_counterpart_id', 'category_id', 'category_source']))
        ->toBe(['is_transfer' => false, 'transfer_source' => null, 'transfer_counterpart_id' => null, 'category_id' => $other->id, 'category_source' => FieldSource::Manual]);
});

test('undoing an import unpairs every pair with a created row; a survivor a rule marked stays a transfer', function () {
    $other = $this->user->categories()->where('name', 'Other')->sole();
    $plain = Transaction::factory()->for($this->savings)->create(['date' => '2026-10-09', 'time' => '06:00:00', 'amount' => -30000, 'description' => 'To Current', 'category_id' => $other->id, 'category_source' => FieldSource::Manual]);
    $ruled = Transaction::factory()->for($this->savings)->create(['date' => '2026-10-02', 'time' => '08:00:00', 'amount' => 50, 'description' => 'Refund', 'is_transfer' => true, 'transfer_source' => TransferSource::Rule]);

    $batch = importTransfers('transfers.csv');

    expect($batch->summary['paired_as_transfers'])->toBe(6)
        ->and($plain->refresh()->transferCounterpart->description)->toBe('Payment from Someone')
        ->and($plain->category_id)->toBeNull()
        ->and($ruled->refresh()->transferCounterpart->description)->toBe('Kiosk')
        ->and($ruled->transfer_source)->toBe(TransferSource::Rule);

    $this->post(route('finance.batches.undo', $batch))->assertRedirect();

    expect(Transaction::query()->pluck('id')->sort()->values()->all())->toBe([$plain->id, $ruled->id])
        ->and($plain->refresh()->only(['is_transfer', 'transfer_source', 'transfer_counterpart_id', 'category_id']))
        ->toBe(['is_transfer' => false, 'transfer_source' => null, 'transfer_counterpart_id' => null, 'category_id' => $other->id])
        ->and($ruled->refresh()->only(['is_transfer', 'transfer_source', 'transfer_counterpart_id']))
        ->toBe(['is_transfer' => true, 'transfer_source' => TransferSource::Rule, 'transfer_counterpart_id' => null])
        ->and(TransferSuggestion::query()->count())->toBe(0);
});

test('unpairing by hand keeps both sides as transfers that find never pairs again', function () {
    importTransfers('transfers.csv');
    $outflow = row($this->current, '2026-10-01 09:00:00', -50);
    $inflow = $outflow->transferCounterpart;

    $this->delete(route('finance.transactions.unpair', $outflow))->assertSessionHasNoErrors();
    $this->post(route('finance.transfers.find'));

    foreach ([$outflow, $inflow] as $side) {
        expect($side->refresh()->only(['is_transfer', 'transfer_source', 'transfer_counterpart_id']))
            ->toBe(['is_transfer' => true, 'transfer_source' => TransferSource::Manual, 'transfer_counterpart_id' => null]);
    }

    // They can still be paired by hand.
    $this->post(route('finance.transactions.pair', $outflow), ['counterpart_id' => $inflow->id])->assertSessionHasNoErrors();
    expect($outflow->refresh()->transfer_counterpart_id)->toBe($inflow->id);
});

test('unmarking a paired transaction releases its counterpart, which may pair again', function () {
    importTransfers('transfers.csv');
    $outflow = row($this->current, '2026-10-01 09:00:00', -50);
    $inflow = $outflow->transferCounterpart;

    $this->delete(route('finance.transactions.unmark-transfer', $outflow))->assertSessionHasNoErrors();

    expect($outflow->refresh()->only(['is_transfer', 'transfer_source', 'transfer_counterpart_id']))
        ->toBe(['is_transfer' => false, 'transfer_source' => TransferSource::Manual, 'transfer_counterpart_id' => null])
        ->and($inflow->refresh()->only(['is_transfer', 'transfer_source']))->toBe(['is_transfer' => false, 'transfer_source' => null]);

    // The freed inflow's only candidate left is the Kiosk payment, 23 hours later.
    $this->post(route('finance.transfers.find'));

    expect($outflow->refresh()->is_transfer)->toBeFalse()
        ->and($inflow->refresh()->transferCounterpart->description)->toBe('Kiosk');
});

test('marking a transfer without a counterpart clears its category, and a transfer takes no category', function () {
    $transaction = Transaction::factory()->for($this->current)->create(['amount' => 30000, 'description' => 'Payment from Someone']);
    $income = $this->user->categories()->where('name', 'Income')->sole();
    $this->patch(route('finance.transactions.update', $transaction), ['category_id' => $income->id]);

    $this->post(route('finance.transactions.mark-transfer', $transaction))->assertSessionHasNoErrors();

    expect($transaction->refresh()->only(['is_transfer', 'transfer_source', 'category_id']))
        ->toBe(['is_transfer' => true, 'transfer_source' => TransferSource::Manual, 'category_id' => null]);

    $this->patch(route('finance.transactions.update', $transaction), ['category_id' => $income->id])->assertSessionHasErrors('category_id');
    expect($transaction->refresh()->category_id)->toBeNull();
});

test('pairing by hand needs two unpaired transactions moving money between two accounts', function (string $case, string $message) {
    $out = Transaction::factory()->for($this->current)->create(['amount' => -1000]);
    $counterpart = match ($case) {
        'same account' => Transaction::factory()->for($this->current)->create(['amount' => 1000]),
        'same sign' => Transaction::factory()->for($this->savings)->create(['amount' => -1000]),
        'already paired' => tap(Transaction::factory()->for($this->savings)->create(['amount' => 1000]), function (Transaction $paired): void {
            $third = Transaction::factory()->for($this->current)->create(['amount' => -1000]);
            $this->post(route('finance.transactions.pair', $third), ['counterpart_id' => $paired->id]);
        }),
    };

    $this->post(route('finance.transactions.pair', $out), ['counterpart_id' => $counterpart->id])->assertSessionHasErrors(['transfer' => $message]);
    expect($out->refresh()->is_transfer)->toBeFalse();
})->with([
    ['same account', 'Pick a transaction in another account.'],
    ['same sign', 'A transfer pairs money leaving one account with money arriving in another.'],
    ['already paired', 'That transaction is already paired. Unpair it first.'],
]);

test('pair candidates are the closest opposite amounts in other accounts, or a search', function () {
    importTransfers('transfers.csv');
    $kiosk = row($this->current, '2026-10-02 08:00:00', -50);

    // Paired rows are no candidates; the Kiosk has none left within 3 days.
    $this->getJson(route('finance.transactions.transfer-candidates', $kiosk))->assertOk()->assertJsonCount(0, 'candidates');

    $this->getJson(route('finance.transactions.transfer-candidates', [$kiosk, 'search' => 'travel']))->assertOk()
        ->assertJsonPath('candidates.0.description', 'To pocket Travel from CHF')
        ->assertJsonPath('candidates.0.account', 'Savings');
});

test('a mark_as_transfer rule marks matches without a category and pairing keeps it a rule transfer', function () {
    $income = $this->user->categories()->where('name', 'Income')->sole();
    $payment = Transaction::factory()->for($this->current)->create(['date' => '2026-10-09', 'time' => '06:00:00', 'amount' => 30000, 'description' => 'Payment from Someone']);
    $unmarked = Transaction::factory()->for($this->current)->create(['amount' => 1000, 'description' => 'Payment from Grandma']);
    $this->delete(route('finance.transactions.unmark-transfer', $unmarked));

    $this->post(route('finance.rules.store'), [
        'conditions' => [['field' => 'description', 'operator' => 'starts_with', 'value' => 'payment from']],
        'actions' => [['type' => 'set_category', 'value' => $income->id], ['type' => 'mark_as_transfer']],
    ])->assertSessionHasNoErrors();

    $this->get(route('finance.rules.review-all'))->assertInertia(fn ($page) => $page
        ->where('transactions', 2)
        ->where('changes', ['category' => 1, 'payee' => 0, 'transfer' => 1]));
    $this->post(route('finance.rules.apply-all'));

    // A transfer takes no category, even from the same rule; a row the user unmarked stays
    // unmarked and gets the category instead.
    expect($payment->refresh()->only(['is_transfer', 'transfer_source', 'category_id']))
        ->toBe(['is_transfer' => true, 'transfer_source' => TransferSource::Rule, 'category_id' => null])
        ->and($unmarked->refresh()->only(['is_transfer', 'category_id']))->toBe(['is_transfer' => false, 'category_id' => $income->id]);

    $savingsSide = Transaction::factory()->for($this->savings)->create(['date' => '2026-10-10', 'time' => '07:00:00', 'amount' => -30000, 'description' => 'To Current']);
    $this->post(route('finance.transfers.find'));

    expect($payment->refresh()->transfer_counterpart_id)->toBe($savingsSide->id)
        ->and($payment->transfer_source)->toBe(TransferSource::Rule)
        ->and($savingsSide->refresh()->transfer_source)->toBe(TransferSource::Auto);
});
