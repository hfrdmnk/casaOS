<?php

namespace App\Actions\Finance\Rules;

use App\Models\Finance\FieldSource;
use App\Models\Finance\RuleActionType;
use App\Models\Finance\Transaction;
use Illuminate\Validation\ValidationException;

/**
 * Sets a transaction's category or payee by hand. A value set here is manual and rules never
 * overwrite it. Clearing a field (null) hands it back to the rules, which run again at once.
 */
final class EditTransaction
{
    public function __construct(private RuleEngine $engine) {}

    /**
     * @param  array{category_id?: int|null, payee_id?: int|null}  $fields  only the given keys change
     * @return array<string, array{from: int|null, to: int}> what the rules set after a clear
     */
    public function __invoke(Transaction $transaction, array $fields): array
    {
        $cleared = false;

        foreach (RuleActionType::cases() as $type) {
            if (! array_key_exists($type->column(), $fields)) {
                continue;
            }

            $value = $fields[$type->column()];
            $owned = $value === null || $type->model()::query()->where('user_id', $transaction->user_id)->whereKey($value)->exists();

            if (! $owned) {
                throw ValidationException::withMessages([$type->column() => $type === RuleActionType::SetCategory ? 'Choose one of your categories.' : 'Choose one of your payees.']);
            }

            $transaction->setAttribute($type->column(), $value === null ? null : (int) $value);
            $transaction->setAttribute($type->sourceColumn(), $value === null ? null : FieldSource::Manual);
            $cleared = $cleared || $value === null;
        }

        $changes = $cleared ? $this->engine->run($transaction, $this->engine->rulesFor($transaction->user)) : [];
        $transaction->save();

        return $changes;
    }
}
