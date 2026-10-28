<?php

namespace App\Actions\Finance\Import;

use App\Actions\Finance\Subscriptions\ManageSubscriptions;
use App\Actions\Finance\Transfers\ManageTransfers;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\Category;
use App\Models\Finance\FieldSource;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportBatchChange;
use App\Models\Finance\Payee;
use App\Models\Finance\RuleActionType;
use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionLinkSource;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransferSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reverts everything one import batch did, from its journal, newest change first: created
 * transactions and snapshots are deleted, updated ones get their imported fields back, and
 * transactions removed as reverted are restored. A category or payee the import's rules set on an
 * updated transaction is restored too, unless it has been set by hand since.
 *
 * Transfer pairs go first: every pair that involves a transaction the batch created, or one it
 * completed in place (which goes back to pending), is broken. The surviving side falls back to a
 * plain transaction when pairing made it a transfer (and gets back the category pairing cleared),
 * or stays a transfer without a counterpart when a rule or the user marked it.
 *
 * Subscriptions are never deleted: charges the batch created go with it, pre-existing charges it
 * linked automatically are unlinked, and every subscription that lost or regained a charge gets
 * its next expected date recomputed. Detection then runs again so no suggestion points at a
 * removed charge.
 */
final class UndoImportBatch
{
    /**
     * The transaction fields an import may change on an existing transaction.
     */
    public const IMPORTED_TRANSACTION_FIELDS = ['amount', 'fee', 'status', 'balance_after', 'fingerprint'];

    /**
     * The fields rules may change when an import updates a transaction.
     */
    public const RULE_TRANSACTION_FIELDS = ['category_id', 'category_source', 'payee_id', 'payee_source', 'is_transfer', 'transfer_source', 'transfer_counterpart_id'];

    public function __construct(private ManageTransfers $transfers, private ManageSubscriptions $subscriptions) {}

    /**
     * Batches are undone newest first per account: a batch that a later, still active batch built
     * on (by importing into one of its accounts) cannot be undone until that one is.
     */
    public function blockedBy(ImportBatch $batch): ?ImportBatch
    {
        return ImportBatch::query()
            ->where('user_id', $batch->user_id)
            ->whereNull('undone_at')
            ->where('id', '>', $batch->id)
            ->orderBy('id')
            ->get()
            ->first(fn (ImportBatch $later): bool => array_intersect($later->accountIds(), $batch->accountIds()) !== []);
    }

    public function __invoke(ImportBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $changes = $batch->changes()->orderByDesc('id')->get();
            $this->breakPairs($changes);
            $ids = $changes->where('subject_type', 'transaction')->pluck('subject_id');
            $subscriptions = Transaction::query()->whereIn('id', $ids)->whereNotNull('subscription_id')->pluck('subscription_id')
                ->merge($changes->where('action', 'removed')->pluck('before.subscription_id')->filter());

            $changes->each(fn (ImportBatchChange $change) => match ($change->subject_type) {
                'transaction' => $this->revertTransaction($change),
                default => $this->revertSnapshot($change),
            });

            Subscription::query()->whereIn('id', $subscriptions->unique()->values())->get()
                ->each(fn (Subscription $subscription) => $this->subscriptions->refreshNextDate($subscription));

            $batch->changes()->delete();
            $batch->forceFill(['undone_at' => now()])->save();
            $this->subscriptions->detect($batch->user);
        });
    }

    /**
     * Break the pairs of transactions the batch created, and the pairs a completed-in-place
     * transaction got that were not made by hand.
     *
     * @param  Collection<int, ImportBatchChange>  $changes
     */
    private function breakPairs(Collection $changes): void
    {
        $created = $changes->where('subject_type', 'transaction')->where('action', 'created')->pluck('subject_id');
        $updated = $changes->where('subject_type', 'transaction')->where('action', 'updated')->pluck('subject_id');

        Transaction::query()->whereIn('id', $created)->whereNotNull('transfer_counterpart_id')->get()
            ->each(fn (Transaction $transaction) => $this->transfers->detach($transaction));

        Transaction::query()->whereIn('id', $updated)->whereNotNull('transfer_counterpart_id')
            ->where('transfer_source', '!=', TransferSource::Manual)->get()
            ->each(fn (Transaction $transaction) => $this->transfers->detach($transaction));
    }

    private function revertTransaction(ImportBatchChange $change): void
    {
        $before = (array) $change->before;

        match ($change->action) {
            'created' => Transaction::query()->whereKey($change->subject_id)->delete(),
            'updated' => Transaction::query()->whereKey($change->subject_id)->update($this->updatedFields($change->subject_id, $before)),
            'paired' => $this->revertPaired($change->subject_id, $before),
            'subscribed' => Transaction::query()->whereKey($change->subject_id)->where('subscription_source', SubscriptionLinkSource::Auto)
                ->update(['subscription_id' => $before['subscription_id'] ?? null, 'subscription_source' => $before['subscription_source'] ?? null]),
            default => Transaction::query()->insert([
                ...$before,
                ...$this->existingReference($before, 'category_id', 'category_source', Category::class),
                ...$this->existingReference($before, 'payee_id', 'payee_source', Payee::class),
                ...$this->unpaired($before),
            ]),
        };
    }

    /**
     * A transaction that existed before the batch and was paired by it. Once its pair is broken
     * and it is a plain transaction again, it gets back the category pairing cleared.
     *
     * @param  array<string, mixed>  $before
     */
    private function revertPaired(int $id, array $before): void
    {
        $current = Transaction::query()->find($id);

        if ($current === null || $current->is_transfer || $current->category_id !== null) {
            return;
        }

        $current->forceFill($this->existingReference($before, 'category_id', 'category_source', Category::class))->save();
    }

    /**
     * A removed transaction comes back without its counterpart, which lost the pair when it was
     * removed; a transfer only pairing had made is a plain transaction again.
     *
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function unpaired(array $before): array
    {
        return [
            'transfer_counterpart_id' => null,
            ...(($before['transfer_source'] ?? null) === TransferSource::Auto->value ? ['is_transfer' => false, 'transfer_source' => null] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function updatedFields(int $id, array $before): array
    {
        $fields = array_intersect_key($before, array_flip(self::IMPORTED_TRANSACTION_FIELDS));
        $current = Transaction::query()->find($id);

        if ($current === null || ! array_key_exists('category_source', $before)) {
            return $fields;
        }

        foreach (RuleActionType::valued() as $type) {
            if ($current->getAttribute($type->sourceColumn()) !== FieldSource::Manual) {
                $fields += $this->existingReference($before, $type->column(), $type->sourceColumn(), $type->model());
            }
        }

        if (array_key_exists('is_transfer', $before) && $current->transfer_source !== TransferSource::Manual) {
            $fields += [
                'is_transfer' => $before['is_transfer'],
                'transfer_source' => $before['transfer_source'],
                'transfer_counterpart_id' => null,
            ];
        }

        return $fields;
    }

    /**
     * The reference and its source as before, or both null when the row is gone.
     *
     * @param  array<string, mixed>  $before
     * @param  class-string<Category|Payee>  $model
     * @return array<string, mixed>
     */
    private function existingReference(array $before, string $column, string $sourceColumn, string $model): array
    {
        $exists = ($before[$column] ?? null) !== null && $model::query()->whereKey($before[$column])->exists();

        return [$column => $exists ? $before[$column] : null, $sourceColumn => $exists ? ($before[$sourceColumn] ?? null) : null];
    }

    private function revertSnapshot(ImportBatchChange $change): void
    {
        $before = (array) $change->before;

        match ($change->action) {
            'created' => BalanceSnapshot::query()->whereKey($change->subject_id)->delete(),
            default => BalanceSnapshot::query()->whereKey($change->subject_id)->update([
                'balance' => $before['balance'],
                'source' => $before['source'],
                'import_batch_id' => $before['import_batch_id'],
            ]),
        };
    }
}
