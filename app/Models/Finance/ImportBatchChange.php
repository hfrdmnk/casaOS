<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One journal entry of an import batch, used to undo it.
 *
 * @property int $id
 * @property int $import_batch_id
 * @property string $subject_type `transaction` or `snapshot`
 * @property int $subject_id
 * @property string $action `created`, `updated`, `removed`, `paired` (transfer pairing changed a
 *                          pre-existing transaction) or `subscribed` (it was linked to a subscription)
 * @property array<string, mixed>|null $before
 */
#[Fillable(['subject_type', 'subject_id', 'action', 'before'])]
class ImportBatchChange extends Model
{
    /**
     * @return BelongsTo<ImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
        ];
    }
}
