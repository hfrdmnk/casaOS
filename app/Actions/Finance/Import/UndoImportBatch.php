<?php

namespace App\Actions\Finance\Import;

use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\Category;
use App\Models\Finance\FieldSource;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportBatchChange;
use App\Models\Finance\Payee;
use App\Models\Finance\RuleActionType;
use App\Models\Finance\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Reverts everything one import batch did, from its journal, newest change first: created
 * transactions and snapshots are deleted, updated ones get their imported fields back, and
 * transactions removed as reverted are restored. A category or payee the import's rules set on an
 * updated transaction is restored too, unless it has been set by hand since.
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
    public const RULE_TRANSACTION_FIELDS = ['category_id', 'category_source', 'payee_id', 'payee_source'];

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
            'updated' => Transaction::query()->whereKey($change->subject_id)->update($this->updatedFields($change->subject_id, $before)),
            default => Transaction::query()->insert([
                ...$before,
                ...$this->existingReference($before, 'category_id', 'category_source', Category::class),
                ...$this->existingReference($before, 'payee_id', 'payee_source', Payee::class),
            ]),
        };
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

        foreach (RuleActionType::cases() as $type) {
            if ($current->getAttribute($type->sourceColumn()) !== FieldSource::Manual) {
                $fields += $this->existingReference($before, $type->column(), $type->sourceColumn(), $type->model());
            }
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
