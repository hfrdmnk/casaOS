<?php

namespace App\Models\Finance;

use App\Actions\Finance\Rules\ManageRules;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\Finance\RuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A deterministic categorization rule. All `conditions` must match (AND); every matching enabled
 * rule applies its `actions` in `position` order, so a later rule overrides an earlier one for the
 * same field. Create and change rules through {@see ManageRules},
 * which validates them.
 *
 * Condition values: description operators take a string (regex: a PCRE pattern without
 * delimiters); `payee` and `account` take an id; amounts take signed Rappen, `between` a
 * [min, max] pair (inclusive). Action values are a category or payee id; `mark_as_transfer` has
 * none.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property int $position
 * @property bool $enabled
 * @property list<array{field: string, operator: string, value: string|int|list<int>}> $conditions
 * @property list<array{type: string, value?: int}> $actions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'position', 'enabled', 'conditions', 'actions'])]
class Rule extends Model
{
    /** @use HasFactory<RuleFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Whether an action sets the given category or payee, or a condition needs the payee.
     *
     * @param  'category'|'payee'  $kind
     */
    public function references(string $kind, int $id): bool
    {
        $action = $kind === 'category' ? RuleActionType::SetCategory : RuleActionType::SetPayee;

        return collect($this->actions)->contains(fn (array $item): bool => $item['type'] === $action->value && (int) ($item['value'] ?? 0) === $id)
            || ($kind === 'payee' && collect($this->conditions)->contains(fn (array $item): bool => $item['field'] === RuleConditionField::Payee->value && (int) $item['value'] === $id));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'enabled' => 'boolean',
            'conditions' => 'array',
            'actions' => 'array',
        ];
    }
}
