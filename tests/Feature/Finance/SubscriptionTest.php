<?php

use App\Models\Finance\Account;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Payee;
use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionInterval;
use App\Models\Finance\SubscriptionLinkSource;
use App\Models\Finance\SubscriptionSource;
use App\Models\Finance\SubscriptionStatus;
use App\Models\Finance\SubscriptionSuggestion;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\Finance\TransferSource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['name' => 'Current']);
    $this->actingAs($this->user);
});

/**
 * One charge per date (Y-m-d => CHF); `{n}` in the description becomes a different reference number each time.
 *
 * @param  array<string, float>  $charges
 */
function charges(Account $account, string $description, array $charges, array $attributes = []): void
{
    foreach ($charges as $date => $chf) {
        Transaction::factory()->for($account)->create([
            'date' => $date,
            'description' => str_replace('{n}', (string) random_int(1000, 9999), $description),
            'amount' => (int) round(-$chf * 100),
            ...$attributes,
        ]);
    }
}

function detect(): void
{
    test()->post(route('finance.subscriptions.detect'))->assertRedirect();
}

/**
 * @return array<string, array{interval: string, amount: int, next_date: string, charges: int}>
 */
function openSuggestions(User $user): array
{
    return SubscriptionSuggestion::query()->ownedBy($user)->whereNull('dismissed_at')->get()
        ->mapWithKeys(fn (SubscriptionSuggestion $suggestion): array => [$suggestion->match_key => [
            'interval' => $suggestion->interval->value,
            'amount' => $suggestion->amount,
            'next_date' => $suggestion->next_date,
            'charges' => count($suggestion->transaction_ids),
        ]])->sortKeys()->all();
}

function importCsv(string $csv, string $name = 'bank.csv'): ImportBatch
{
    test()->post(route('finance.import.store'), ['file' => UploadedFile::fake()->createWithContent($name, $csv)])->assertRedirect();

    return ImportBatch::query()->latest('id')->firstOrFail();
}

test('detection suggests regular charges and leaves irregular, drifting, short and transfer groups alone', function () {
    $netflix = Payee::factory()->for($this->user)->create(['name' => 'Netflix']);
    // Monthly by payee, different raw descriptions.
    charges($this->account, 'NETFLIX.COM {n}', ['2026-07-03' => 15.90, '2026-08-03' => 15.90, '2026-09-02' => 15.90, '2026-10-03' => 15.90], ['payee_id' => $netflix->id]);
    // Monthly by description, the reference number differs; the price rises by less than 10%.
    charges($this->account, 'GYM FIT *{n}', ['2026-08-10' => 49.00, '2026-09-10' => 50.50, '2026-10-10' => 52.00]);
    // Yearly.
    charges($this->account, 'Domain renewal example.ch', ['2024-10-01' => 18.00, '2025-10-02' => 18.00, '2026-09-30' => 19.00]);
    // Monthly dates, but each amount is far from the median: usage-based, not a subscription.
    charges($this->account, 'Cloud usage', ['2026-07-15' => 3.10, '2026-08-15' => 5.40, '2026-09-15' => 8.90, '2026-10-15' => 12.75]);
    // Same amount, irregular gaps.
    charges($this->account, 'Kiosk', ['2026-08-01' => 9.50, '2026-08-20' => 9.50, '2026-09-25' => 9.50, '2026-10-05' => 9.50]);
    // One gap of 34 days breaks the monthly run: only the last two remain.
    charges($this->account, 'Magazine', ['2026-07-01' => 8.00, '2026-08-04' => 8.00, '2026-09-04' => 8.00]);
    // Only two charges.
    charges($this->account, 'Hetzner', ['2026-09-27' => 10.32, '2026-10-27' => 10.09]);
    // Regular, but transfers are neither income nor spending.
    charges($this->account, 'To pocket', ['2026-08-01' => 50.00, '2026-09-01' => 50.00, '2026-10-01' => 50.00], ['is_transfer' => true, 'transfer_source' => TransferSource::Manual]);
    // Regular, but pending charges are not part of detection yet.
    charges($this->account, 'Pending club', ['2026-08-01' => 20.00, '2026-09-01' => 20.00, '2026-10-01' => 20.00], ['status' => TransactionStatus::Pending]);

    detect();

    expect(openSuggestions($this->user))->toBe([
        'description:domain renewal example ch' => ['interval' => 'yearly', 'amount' => 1800, 'next_date' => '2027-09-30', 'charges' => 3],
        'description:gym fit' => ['interval' => 'monthly', 'amount' => 5050, 'next_date' => '2026-11-10', 'charges' => 3],
        "payee:{$netflix->id}" => ['interval' => 'monthly', 'amount' => 1590, 'next_date' => '2026-11-03', 'charges' => 4],
    ]);
});

test('a charge more than 10% from the median drops out, and the run is built from the rest', function () {
    // The median of the five is 20.00: 22.00 is exactly 10% above and stays, 22.50 drops out.
    // Without it, July to November is one monthly run of five charges; with it, the August gap breaks it.
    charges($this->account, 'Club', ['2026-07-01' => 20.00, '2026-08-01' => 22.50, '2026-09-01' => 20.00, '2026-10-01' => 22.00, '2026-11-01' => 20.00]);

    detect();

    expect(openSuggestions($this->user))->toBe([
        'description:club' => ['interval' => 'monthly', 'amount' => 2000, 'next_date' => '2026-12-01', 'charges' => 3],
    ]);
});

test('confirming a suggestion creates a detected subscription with its charges, and dismissing one sticks', function () {
    charges($this->account, 'STREAMFLIX *{n}', ['2026-08-03' => 15.90, '2026-09-03' => 15.90, '2026-10-03' => 15.90]);
    charges($this->account, 'Gym', ['2026-08-10' => 49.00, '2026-09-10' => 49.00, '2026-10-10' => 49.00]);
    detect();
    $streamflix = SubscriptionSuggestion::query()->where('match_key', 'description:streamflix')->firstOrFail();
    $gym = SubscriptionSuggestion::query()->where('match_key', 'description:gym')->firstOrFail();

    $this->post(route('finance.subscriptions.confirm', $streamflix))->assertRedirect();
    $this->post(route('finance.subscriptions.dismiss', $gym))->assertRedirect();

    $subscription = Subscription::query()->ownedBy($this->user)->sole();
    expect($subscription->only(['name', 'description_match', 'account_id', 'amount', 'next_date']))->toBe([
        'name' => 'Streamflix', 'description_match' => 'streamflix', 'account_id' => $this->account->id, 'amount' => 1590, 'next_date' => '2026-11-03',
    ])->and($subscription->source)->toBe(SubscriptionSource::Detected)
        ->and($subscription->transactions()->count())->toBe(3);

    detect();
    expect(openSuggestions($this->user))->toBe([])
        ->and(SubscriptionSuggestion::query()->whereNotNull('dismissed_at')->count())->toBe(1);

    $this->get(route('finance.subscriptions.index'))->assertInertia(fn ($page) => $page
        ->component('finance/subscriptions/index')
        ->has('active', 1)->where('active.0.monthly', 1590)->where('monthly_total', 1590)->has('suggestions', 0));
});

test('mark as subscription links every matching charge, also pending ones, and predicts the next date', function () {
    $hetzner = Payee::factory()->for($this->user)->create(['name' => 'Hetzner']);
    charges($this->account, 'Hetzner Online', ['2026-09-27' => 10.42], ['payee_id' => $hetzner->id]);
    charges($this->account, 'Hetzner Online', ['2026-10-27' => 10.09], ['payee_id' => $hetzner->id, 'status' => TransactionStatus::Pending]);
    // Same payee, but far from the amount: not a match.
    charges($this->account, 'Hetzner Online', ['2026-10-01' => 40.00], ['payee_id' => $hetzner->id]);
    charges($this->account, 'ente.io', ['2026-09-03' => 4.88, '2026-10-03' => 5.03]);
    // Contains "ente" but not the words "ente io".
    charges($this->account, 'Parkplatz Rente', ['2026-10-05' => 5.00]);
    $first = Transaction::query()->where('date', '2026-09-27')->firstOrFail();
    $ente = Transaction::query()->where('date', '2026-10-03')->firstOrFail();

    $this->post(route('finance.subscriptions.store'), ['transaction_id' => $first->id, 'name' => 'Hetzner', 'amount' => '10.42', 'interval' => 'monthly'])->assertRedirect();
    $this->post(route('finance.subscriptions.store'), ['transaction_id' => $ente->id, 'name' => 'ente.io', 'amount' => '5.03', 'interval' => 'monthly'])->assertRedirect();

    $subscriptions = Subscription::query()->ownedBy($this->user)->orderBy('id')->get();
    expect($subscriptions[0]->payee_id)->toBe($hetzner->id)
        ->and($subscriptions[0]->transactions()->orderBy('date')->pluck('date')->all())->toBe(['2026-09-27', '2026-10-27'])
        ->and($subscriptions[0]->next_date)->toBe('2026-11-27')
        ->and($subscriptions[1]->description_match)->toBe('ente io')
        ->and($subscriptions[1]->transactions()->orderBy('date')->pluck('date')->all())->toBe(['2026-09-03', '2026-10-03'])
        ->and($subscriptions[1]->next_date)->toBe('2026-11-03')
        ->and($subscriptions[1]->source)->toBe(SubscriptionSource::Manual);

    $this->get(route('finance.transactions.index', ['subscription' => 'any']))->assertInertia(fn ($page) => $page
        ->where('transactions.total', 4)->where('transactions.data.0.subscription', 'Hetzner'));
});

test('a transfer or an incoming amount cannot be marked as a subscription', function () {
    $transfer = Transaction::factory()->for($this->account)->create(['amount' => -5000, 'is_transfer' => true, 'transfer_source' => TransferSource::Manual]);
    $income = Transaction::factory()->for($this->account)->create(['amount' => 5000]);

    foreach ([$transfer, $income] as $transaction) {
        $this->post(route('finance.subscriptions.store'), ['transaction_id' => $transaction->id, 'name' => 'X', 'amount' => '50', 'interval' => 'monthly'])
            ->assertSessionHasErrors('subscription');
    }

    expect(Subscription::query()->count())->toBe(0);
});

test('linking by hand moves the next date, unlinking sticks, and cancelling stops new links', function () {
    $subscription = Subscription::factory()->for($this->user)->create(['description_match' => 'gym', 'amount' => 4900, 'interval' => SubscriptionInterval::Monthly, 'next_date' => '2026-09-10']);
    $odd = Transaction::factory()->for($this->account)->create(['date' => '2026-10-12', 'description' => 'Fitness voucher', 'amount' => -6000]);

    $this->post(route('finance.transactions.link-subscription', $odd), ['subscription_id' => $subscription->id])->assertRedirect();
    expect($odd->fresh()->subscription_source)->toBe(SubscriptionLinkSource::Manual)
        ->and($subscription->fresh()->next_date)->toBe('2026-11-12');

    $this->delete(route('finance.transactions.unlink-subscription', $odd))->assertRedirect();
    expect($odd->fresh()->subscription_id)->toBeNull()
        ->and($subscription->fresh()->next_date)->toBe('2026-11-12');

    $this->patch(route('finance.subscriptions.update', $subscription), ['description_match' => 'fitness voucher', 'amount' => '60.00'])->assertRedirect();
    expect($odd->fresh()->subscription_id)->toBeNull();

    $this->post(route('finance.subscriptions.cancel', $subscription))->assertRedirect();
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);

    ImportMapping::factory()->for($this->account)->create();
    importCsv("Date,Description,Amount\n2026-11-12,Fitness voucher,-60.00\n");
    expect($subscription->fresh()->transactions()->count())->toBe(0);
});

test('an import links new matching charges and moves the next date; undo unlinks them and keeps the subscription', function () {
    ImportMapping::factory()->for($this->account)->create();
    importCsv("Date,Description,Amount\n2026-08-03,STREAMFLIX 1,-15.90\n2026-09-03,STREAMFLIX 2,-15.90\n2026-10-03,STREAMFLIX 3,-15.90\n", 'first.csv');
    $suggestion = SubscriptionSuggestion::query()->ownedBy($this->user)->sole();
    $this->post(route('finance.subscriptions.confirm', $suggestion))->assertRedirect();
    $subscription = Subscription::query()->ownedBy($this->user)->sole();
    expect($subscription->next_date)->toBe('2026-11-03');

    $batch = importCsv("Date,Description,Amount\n2026-11-04,STREAMFLIX 4,-16.50\n2026-11-05,Coffee,-4.50\n", 'second.csv');

    expect($batch->summary['linked_to_subscriptions'])->toBe(1)
        ->and($subscription->fresh()->transactions()->count())->toBe(4)
        ->and($subscription->fresh()->next_date)->toBe('2026-12-04');

    $this->post(route('finance.batches.undo', $batch))->assertRedirect();

    expect($subscription->fresh())->not->toBeNull()
        ->and($subscription->fresh()->transactions()->count())->toBe(3)
        ->and($subscription->fresh()->next_date)->toBe('2026-11-03');

    // Undoing the batch that created the confirmed subscription's charges keeps the subscription too.
    $this->post(route('finance.batches.undo', ImportBatch::query()->where('filename', 'first.csv')->firstOrFail()))->assertRedirect();
    expect(Subscription::query()->ownedBy($this->user)->count())->toBe(1)
        ->and(SubscriptionSuggestion::query()->ownedBy($this->user)->count())->toBe(0);
});

test('undo unlinks a pre-existing charge that the import completed in place and linked', function () {
    ImportMapping::factory()->for($this->account)->create([
        'header' => ['Date', 'Description', 'Amount', 'State'],
        'status_column' => 3,
        'status_map' => ['COMPLETED' => 'completed', 'PENDING' => 'pending'],
    ]);
    $subscription = Subscription::factory()->for($this->user)->create(['description_match' => 'hetzner', 'amount' => 1032, 'next_date' => '2026-10-27']);

    // Pending at an amount that does not match yet.
    importCsv("Date,Description,Amount,State\n2026-10-27,Hetzner,-15.00,PENDING\n", 'pending.csv');
    $charge = Transaction::query()->ownedBy($this->user)->sole();
    expect($charge->subscription_id)->toBeNull();

    $batch = importCsv("Date,Description,Amount,State\n2026-10-27,Hetzner,-10.32,COMPLETED\n", 'completed.csv');
    expect($batch->summary['updated'])->toBe(1)
        ->and($charge->fresh()->subscription_id)->toBe($subscription->id)
        ->and($subscription->fresh()->next_date)->toBe('2026-11-27');

    $this->post(route('finance.batches.undo', $batch))->assertRedirect();

    expect($charge->fresh()->only(['amount', 'subscription_id', 'subscription_source']))->toBe(['amount' => -1500, 'subscription_id' => null, 'subscription_source' => null])
        ->and($charge->fresh()->status)->toBe(TransactionStatus::Pending);
});
