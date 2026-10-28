<?php

namespace App\Actions\Finance\Import;

use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\Category;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportBatchChange;
use App\Models\Finance\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Reverts everything one import batch did, from its journal, newest change first: created
 * transactions and snapshots are deleted, updated ones get their imported fields back (categories
 * set by hand stay), and transactions removed as reverted are restored.
 */
final class UndoImportBatch
{
    /**
     * The transaction fields an import may change on an existing transaction.
     */
    public const IMPORTED_TRANSACTION_FIELDS = ['amount', 'fee', 'status', 'balance_after', 'fingerprint'];

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
            $batch->changes()->orderByDesc('id')->get()->each(fn (ImportBatchChange $change) => match ($change->subject_type) {
                'transaction' => $this->revertTransaction($change),
                default => $this->revertSnapshot($change),
            });

            $batch->changes()->delete();
            $batch->forceFill(['undone_at' => now()])->save();
        });
    }

    private function revertTransaction(ImportBatchChange $change): void
    {
        $before = (array) $change->before;

        match ($change->action) {
            'created' => Transaction::query()->whereKey($change->subject_id)->delete(),
            'updated' => Transaction::query()->whereKey($change->subject_id)->update($this->rawTransactionFields($before)),
            default => Transaction::query()->insert([
                ...$before,
                'category_id' => Category::query()->whereKey($before['category_id'] ?? null)->exists() ? $before['category_id'] : null,
            ]),
        };
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

    /**
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function rawTransactionFields(array $before): array
    {
        return array_intersect_key($before, array_flip(self::IMPORTED_TRANSACTION_FIELDS));
    }
}
