<?php

namespace App\Models\Finance;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\Finance\PayeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cleaned-up merchant name, e.g. `Coop` for `Coop Pronto` and `Coop Restaurant`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
class Payee extends Model
{
    /** @use HasFactory<PayeeFactory> */
    use BelongsToUser, HasFactory;

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
