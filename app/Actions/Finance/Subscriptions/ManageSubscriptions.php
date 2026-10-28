<?php

namespace App\Actions\Finance\Subscriptions;

use App\Models\Finance\Account;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\Payee;
use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionInterval;
use App\Models\Finance\SubscriptionLinkSource;
use App\Models\Finance\SubscriptionSource;
use App\Models\Finance\SubscriptionStatus;
use App\Models\Finance\SubscriptionSuggestion;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;

/**
 * Everything a user does with subscriptions: detect and review suggestions, create one by hand or
 * from a transaction, edit, cancel, and link or unlink charges. The web controllers and the MCP
 * tools call this class, so both enforce the same rules (validation errors are thrown as a
 * ValidationException keyed by field, or `subscription`). Another user's rows are not found.
 *
 * A charge matches a subscription when it is a non-transfer outflow (completed or pending) with
 * the subscription's payee (or, without a payee, a normalized description containing
 * `description_match`), in its account when it has one, and within ±10% of its amount. Linking
 * moves the next expected date to one interval after the newest linked charge.
 */
final class ManageSubscriptions
{
    public function __construct(private DetectSubscriptions $detection) {}

    /**
     * "Detect subscriptions": rebuild the open suggestions from all the user's charges.
     *
     * @return array{suggested: int}
     */
    public function detect(User $user): array
    {
        return ($this->detection)($user);
    }

    /**
     * After an import (rules and transfer pairing have run): link the rows it created or
     * completed to the active subscriptions they match, then detect. Pre-existing rows it links are
     * journaled so undo can unlink them.
     *
     * @param  list<int>  $touched
     * @param  list<int>  $created
     * @return array{linked: int, suggested: int}
     */
    public function afterImport(User $user, array $touched, array $created, ImportBatch $batch): array
    {
        $linked = 0;
        $created = array_flip($created);

        foreach (Subscription::query()->ownedBy($user)->active()->with('payee')->orderBy('id')->get() as $subscription) {
            $charges = $this->matching($subscription)->whereIn('id', $touched)->whereNull('subscription_id')
                ->where(fn (Builder $query) => $query->whereNull('subscription_source')->orWhere('subscription_source', '!=', SubscriptionLinkSource::Manual))
                ->get();

            foreach ($charges as $charge) {
                if (! isset($created[$charge->id])) {
                    $batch->changes()->create([
                        'subject_type' => 'transaction',
                        'subject_id' => $charge->id,
                        'action' => 'subscribed',
                        'before' => $charge->only(['subscription_id', 'subscription_source']),
                    ]);
                }

                $charge->forceFill(['subscription_id' => $subscription->id, 'subscription_source' => SubscriptionLinkSource::Auto])->save();
                $linked++;
            }

            if ($charges->isNotEmpty()) {
                $this->refreshNextDate($subscription);
            }
        }

        return ['linked' => $linked, ...$this->detect($user)];
    }

    /**
     * Open suggestions, soonest next date first.
     *
     * @return Collection<int, SubscriptionSuggestion>
     */
    public function suggestions(User $user): Collection
    {
        return SubscriptionSuggestion::query()->ownedBy($user)->whereNull('dismissed_at')
            ->with(['payee', 'account'])->orderBy('next_date')->orderBy('id')->get();
    }

    /**
     * Confirm a suggestion: it becomes a detected subscription with its charges linked (and any
     * other matching charges, e.g. a pending one), and the suggestion goes away.
     */
    public function confirm(SubscriptionSuggestion $suggestion): Subscription
    {
        return DB::transaction(function () use ($suggestion): Subscription {
            $subscription = new Subscription([
                'name' => $suggestion->name,
                'payee_id' => $suggestion->payee_id,
                'description_match' => $suggestion->description_match,
                'account_id' => $suggestion->account_id,
                'amount' => $suggestion->amount,
                'interval' => $suggestion->interval,
                'next_date' => $suggestion->next_date,
                'status' => SubscriptionStatus::Active,
                'source' => SubscriptionSource::Detected,
            ]);
            $subscription->user_id = $suggestion->user_id;
            $subscription->save();

            Transaction::query()->where('user_id', $suggestion->user_id)->whereIn('id', $suggestion->transaction_ids)->whereNull('subscription_id')
                ->update(['subscription_id' => $subscription->id, 'subscription_source' => SubscriptionLinkSource::Auto]);
            $this->linkMatching($subscription);
            $suggestion->delete();

            return $subscription->refresh();
        });
    }

    /**
     * Dismiss a suggestion for good: the same group and interval is never suggested again.
     */
    public function dismiss(SubscriptionSuggestion $suggestion): void
    {
        $suggestion->forceFill(['dismissed_at' => now()])->save();
    }

    /**
     * Create a subscription by hand. Without `next_date`, it is one interval after the newest
     * linked charge (or one interval from today when none matches).
     *
     * @param  array<string, mixed>  $data  name, payee_id, description_match, account_id, amount (positive Rappen), interval, next_date
     */
    public function create(User $user, array $data): Subscription
    {
        $data = $this->validated($user, $data);

        return DB::transaction(function () use ($user, $data): Subscription {
            $subscription = new Subscription([
                ...$data,
                'next_date' => $data['next_date'] ?? SubscriptionInterval::from($data['interval'])->after(today()->toDateString()),
                'status' => SubscriptionStatus::Active,
                'source' => SubscriptionSource::Manual,
            ]);
            $subscription->user_id = $user->id;
            $subscription->save();

            $this->linkMatching($subscription, refresh: ! isset($data['next_date']));

            return $subscription->refresh();
        });
    }

    /**
     * "Mark as subscription" on a charge: a subscription named after its payee (or description),
     * matching by payee or else by its normalized description, in its account, for its amount,
     * monthly unless told otherwise. The charge and every other matching charge are linked.
     *
     * @param  array<string, mixed>  $overrides  any field of create()
     */
    public function createFromTransaction(Transaction $transaction, array $overrides = []): Subscription
    {
        if ($transaction->is_transfer || $transaction->amount >= 0) {
            throw ValidationException::withMessages(['subscription' => 'Only a charge (money leaving, not a transfer) can be a subscription.']);
        }

        $transaction->loadMissing('payee');

        return DB::transaction(function () use ($transaction, $overrides): Subscription {
            $subscription = $this->create($transaction->user, [
                'name' => $transaction->payee !== null ? $transaction->payee->name : $transaction->description,
                'payee_id' => $transaction->payee_id,
                'description_match' => $transaction->payee_id === null ? DetectSubscriptions::normalize($transaction->description) : null,
                'account_id' => $transaction->account_id,
                'amount' => -$transaction->amount,
                'interval' => SubscriptionInterval::Monthly->value,
                ...$overrides,
            ]);

            if ($transaction->refresh()->subscription_id !== $subscription->id) {
                $this->link($subscription, $transaction);
            }

            return $subscription->refresh();
        });
    }

    /**
     * Edit a subscription. Changing how it matches does not unlink charges; it links new matches.
     *
     * @param  array<string, mixed>  $data  any field of create(), plus `status` (active or cancelled)
     */
    public function update(Subscription $subscription, array $data): Subscription
    {
        $data = $this->validated($subscription->user, [
            ...$subscription->only(['name', 'payee_id', 'description_match', 'account_id', 'amount', 'next_date']),
            'interval' => $subscription->interval->value,
            ...$data,
        ], withStatus: true);

        return DB::transaction(function () use ($subscription, $data): Subscription {
            $status = SubscriptionStatus::from($data['status'] ?? $subscription->status->value);
            $subscription->fill([
                ...$data,
                'status' => $status,
                'cancelled_at' => $status === SubscriptionStatus::Cancelled ? ($subscription->cancelled_at ?? now()) : null,
            ])->save();

            if ($status === SubscriptionStatus::Active) {
                $this->linkMatching($subscription, refresh: false);
            }

            return $subscription->refresh();
        });
    }

    /**
     * Cancel: the subscription keeps its history but links no new charges and is not upcoming.
     */
    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => $subscription->cancelled_at ?? now()])->save();

        return $subscription;
    }

    /**
     * Link a charge to a subscription by hand (moving it from another one). Manual is final.
     *
     * @throws ValidationException
     */
    public function link(Subscription $subscription, Transaction $transaction): void
    {
        if ($subscription->user_id !== $transaction->user_id) {
            throw (new ModelNotFoundException)->setModel(Transaction::class, [$transaction->id]);
        }

        if ($transaction->is_transfer) {
            throw ValidationException::withMessages(['subscription' => 'A transfer cannot be a subscription payment.']);
        }

        DB::transaction(function () use ($subscription, $transaction): void {
            $previous = $transaction->subscription;
            $transaction->forceFill(['subscription_id' => $subscription->id, 'subscription_source' => SubscriptionLinkSource::Manual])->save();
            $this->refreshNextDate($subscription);

            if ($previous !== null && ! $previous->is($subscription)) {
                $this->refreshNextDate($previous);
            }
        });
    }

    /**
     * Unlink a charge by hand. It is never linked automatically again.
     *
     * @throws ValidationException
     */
    public function unlink(Transaction $transaction): void
    {
        $subscription = $transaction->subscription;

        if ($subscription === null) {
            throw ValidationException::withMessages(['subscription' => 'This transaction is not linked to a subscription.']);
        }

        DB::transaction(function () use ($transaction, $subscription): void {
            $transaction->forceFill(['subscription_id' => null, 'subscription_source' => SubscriptionLinkSource::Manual])->save();
            $this->refreshNextDate($subscription);
        });
    }

    /**
     * Move the next expected date to one interval after the newest linked charge. Without linked
     * charges the date stays as it is.
     */
    public function refreshNextDate(Subscription $subscription): void
    {
        $latest = $subscription->transactions()->max('date');

        if ($latest !== null) {
            $subscription->forceFill(['next_date' => $subscription->interval->after((string) $latest)])->save();
        }
    }

    /**
     * Charges that match the subscription (linked or not).
     *
     * @return Builder<Transaction>
     */
    public function matching(Subscription $subscription): Builder
    {
        $query = Transaction::query()->where('user_id', $subscription->user_id)
            ->countsAsIncomeOrSpending()
            ->whereIn('status', [TransactionStatus::Completed, TransactionStatus::Pending])
            ->where('amount', '<', 0)
            ->whereBetween('amount', [-(int) floor($subscription->amount * 1.1), -(int) ceil($subscription->amount * 0.9)])
            ->when($subscription->account_id !== null, fn (Builder $query) => $query->where('account_id', $subscription->account_id));

        if ($subscription->payee_id !== null) {
            return $query->where('payee_id', $subscription->payee_id);
        }

        if ($subscription->description_match === null || $subscription->description_match === '') {
            return $query->whereRaw('false');
        }

        $match = ' '.$subscription->description_match.' ';
        $ids = (clone $query)->get(['id', 'description'])
            ->filter(fn (Transaction $transaction): bool => str_contains(' '.DetectSubscriptions::normalize($transaction->description).' ', $match))
            ->modelKeys();

        return $query->whereIn('id', $ids);
    }

    /**
     * Link every unlinked matching charge the user has not unlinked by hand.
     */
    private function linkMatching(Subscription $subscription, bool $refresh = true): void
    {
        $this->matching($subscription)->whereNull('subscription_id')
            ->where(fn (Builder $query) => $query->whereNull('subscription_source')->orWhere('subscription_source', '!=', SubscriptionLinkSource::Manual))
            ->update(['subscription_id' => $subscription->id, 'subscription_source' => SubscriptionLinkSource::Auto]);

        if ($refresh) {
            $this->refreshNextDate($subscription);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(User $user, array $data, bool $withStatus = false): array
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'payee_id' => ['nullable', 'integer'],
            'description_match' => ['nullable', 'string', 'max:200'],
            'account_id' => ['nullable', 'integer'],
            'amount' => ['required', 'integer', 'min:1'],
            'interval' => ['required', ValidationRule::enum(SubscriptionInterval::class)],
            'next_date' => ['nullable', 'date_format:Y-m-d'],
            ...($withStatus ? ['status' => ['sometimes', ValidationRule::enum(SubscriptionStatus::class)]] : []),
        ], ['amount.min' => 'The amount must be more than 0.'])->validate();

        if (isset($validated['payee_id'])) {
            Payee::query()->ownedBy($user)->findOrFail((int) $validated['payee_id']);
        }

        if (isset($validated['account_id'])) {
            Account::query()->ownedBy($user)->findOrFail((int) $validated['account_id']);
        }

        $validated['description_match'] = isset($validated['description_match']) ? DetectSubscriptions::normalize($validated['description_match']) : null;

        if (($validated['payee_id'] ?? null) === null && $validated['description_match'] === '') {
            $validated['description_match'] = null;
        }

        if (($validated['payee_id'] ?? null) === null && $validated['description_match'] === null) {
            throw ValidationException::withMessages(['description_match' => 'Give a payee or a description to match charges by.']);
        }

        return array_intersect_key($validated, array_flip(['name', 'payee_id', 'description_match', 'account_id', 'amount', 'interval', 'next_date', 'status']));
    }
}
