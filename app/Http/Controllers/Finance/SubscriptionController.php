<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Money;
use App\Actions\Finance\Subscriptions\ManageSubscriptions;
use App\Http\Controllers\Controller;
use App\Models\Finance\Account;
use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionInterval;
use App\Models\Finance\SubscriptionStatus;
use App\Models\Finance\SubscriptionSuggestion;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subscriptions, their suggestions, and linking charges from the transaction list. The logic
 * lives in ManageSubscriptions.
 */
class SubscriptionController extends Controller
{
    public function index(Request $request, ManageSubscriptions $subscriptions): Response
    {
        /** @var User $user */
        $user = $request->user();

        $all = Subscription::query()->ownedBy($user)->with(['payee', 'account'])
            ->withCount('transactions')->orderBy('next_date')->orderBy('name')->get();

        return Inertia::render('finance/subscriptions/index', [
            'active' => $all->where('status', SubscriptionStatus::Active)->map(fn (Subscription $subscription): array => self::present($subscription))->values()->all(),
            'cancelled' => $all->where('status', SubscriptionStatus::Cancelled)->sortByDesc('cancelled_at')->map(fn (Subscription $subscription): array => self::present($subscription))->values()->all(),
            'suggestions' => $subscriptions->suggestions($user)->map(fn (SubscriptionSuggestion $suggestion): array => [
                'id' => $suggestion->id,
                'name' => $suggestion->name,
                'match' => $suggestion->payee !== null ? "Payee {$suggestion->payee->name}" : "Description contains “{$suggestion->description_match}”",
                'account' => $suggestion->account?->name,
                'amount' => $suggestion->amount,
                'interval' => $suggestion->interval->value,
                'monthly' => $suggestion->interval->monthly($suggestion->amount),
                'next_date' => $suggestion->next_date,
                'charges' => $suggestion->charges()->map(fn (Transaction $charge): array => ['id' => $charge->id, 'date' => $charge->date, 'amount' => $charge->amount, 'description' => $charge->description])->all(),
            ])->all(),
            'monthly_total' => $all->where('status', SubscriptionStatus::Active)->sum(fn (Subscription $subscription): int => $subscription->monthlyAmount()),
            'intervals' => SubscriptionInterval::options(),
            'accounts' => Account::query()->ownedBy($user)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name])->all(),
            'payees' => TransactionController::payeeOptions($user),
        ]);
    }

    public function detect(Request $request, ManageSubscriptions $subscriptions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $subscriptions->detect($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            ':count subscription suggestion to review.|:count subscription suggestions to review.',
            $result['suggested'],
        )]);

        return back();
    }

    /**
     * Create a subscription: from a transaction ("Mark as subscription", `transaction_id`) or
     * from the form.
     */
    public function store(Request $request, ManageSubscriptions $subscriptions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['transaction_id' => ['nullable', 'integer']]);
        $data = $this->fields($request);

        if ($request->filled('transaction_id')) {
            $transaction = Transaction::query()->ownedBy($user)->findOrFail($request->integer('transaction_id'));
            $subscription = $subscriptions->createFromTransaction($transaction, $data);
        } else {
            $subscription = $subscriptions->create($user, $data);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            'Subscription :name created with :count payment.|Subscription :name created with :count payments.',
            $subscription->transactions()->count(),
            ['name' => $subscription->name],
        )]);

        return back();
    }

    public function update(Request $request, Subscription $subscription, ManageSubscriptions $subscriptions): RedirectResponse
    {
        $subscriptions->update($subscription, $this->fields($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription saved.')]);

        return back();
    }

    public function cancel(Subscription $subscription, ManageSubscriptions $subscriptions): RedirectResponse
    {
        $subscriptions->cancel($subscription);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription cancelled.')]);

        return back();
    }

    public function confirm(SubscriptionSuggestion $suggestion, ManageSubscriptions $subscriptions): RedirectResponse
    {
        abort_if($suggestion->dismissed_at !== null, 404);
        $subscription = $subscriptions->confirm($suggestion);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Subscription :name confirmed.', ['name' => $subscription->name])]);

        return back();
    }

    public function dismiss(SubscriptionSuggestion $suggestion, ManageSubscriptions $subscriptions): RedirectResponse
    {
        $subscriptions->dismiss($suggestion);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Suggestion dismissed. It will not be suggested again.')]);

        return back();
    }

    /**
     * Link a transaction to one of the user's subscriptions by hand.
     */
    public function link(Request $request, Transaction $transaction, ManageSubscriptions $subscriptions): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['subscription_id' => ['required', 'integer']]);
        $subscription = Subscription::query()->ownedBy($user)->findOrFail($request->integer('subscription_id'));
        $subscriptions->link($subscription, $transaction);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Linked to :name.', ['name' => $subscription->name])]);

        return back();
    }

    public function unlink(Transaction $transaction, ManageSubscriptions $subscriptions): RedirectResponse
    {
        $subscriptions->unlink($transaction);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unlinked from the subscription.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'name' => $subscription->name,
            'payee_id' => $subscription->payee_id,
            'payee' => $subscription->payee?->name,
            'description_match' => $subscription->description_match,
            'account_id' => $subscription->account_id,
            'account' => $subscription->account?->name,
            'amount' => $subscription->amount,
            'interval' => $subscription->interval->value,
            'monthly' => $subscription->monthlyAmount(),
            'next_date' => $subscription->next_date,
            'status' => $subscription->status->value,
            'source' => $subscription->source->value,
            'cancelled_at' => $subscription->cancelled_at?->toDateString(),
            'payments' => $subscription->transactions()->with('account')->orderByDesc('date')->orderByDesc('time')->orderByDesc('id')->get()
                ->map(fn (Transaction $charge): array => [
                    'id' => $charge->id,
                    'date' => $charge->date,
                    'description' => $charge->description,
                    'account' => $charge->account->name,
                    'amount' => $charge->amount,
                    'status' => $charge->status->value,
                ])->all(),
        ];
    }

    /**
     * The subscription fields of a web form; the amount is entered in CHF.
     *
     * @return array<string, mixed>
     */
    private function fields(Request $request): array
    {
        $request->validate(['amount' => ['nullable', 'string', 'regex:/^\d{1,6}([.,]\d{1,2})?$/']], ['amount.regex' => 'Enter the amount in CHF, e.g. 12.90.']);
        $keys = array_filter(['name', 'payee_id', 'description_match', 'account_id', 'interval', 'next_date', 'status'], fn (string $key): bool => $request->has($key));
        $data = $request->only($keys);

        foreach (['payee_id', 'account_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] === null || $data[$key] === '' ? null : (int) $data[$key];
            }
        }

        if ($request->filled('amount')) {
            $data['amount'] = Money::parse(str_replace(',', '.', $request->string('amount')->toString()), '.', null);
        }

        if (array_key_exists('next_date', $data) && ($data['next_date'] === null || $data['next_date'] === '')) {
            unset($data['next_date']);
        }

        return $data;
    }
}
