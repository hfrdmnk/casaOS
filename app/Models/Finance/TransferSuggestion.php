<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A possible transfer pair that pairing could not decide alone: the outflow (negative amount) and
 * the inflow (positive) of the same size. Open until the user confirms or dismisses it; a dismissed
 * pair is never suggested or paired automatically again.
 *
 * @property int $id
 * @property int $user_id
 * @property int $outflow_id
 * @property int $inflow_id
 * @property Carbon|null $dismissed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Transaction $outflow
 * @property-read Transaction $inflow
 */
#[Fillable(['outflow_id', 'inflow_id', 'dismissed_at'])]
class TransferSuggestion extends Model
{
    use BelongsToUser;

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function outflow(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'outflow_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function inflow(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'inflow_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
        ];
    }
}
