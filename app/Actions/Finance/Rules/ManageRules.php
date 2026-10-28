<?php

namespace App\Actions\Finance\Rules;

use App\Models\Finance\Account;
use App\Models\Finance\Payee;
use App\Models\Finance\Rule;
use App\Models\Finance\RuleActionType;
use App\Models\Finance\RuleConditionField;
use App\Models\Finance\RuleOperator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes, orders and deletes a user's rules. Every write validates its input here, so
 * the web controllers and later MCP tools reject the same mistakes with the same messages
 * (thrown as a ValidationException keyed like `conditions.0.value`).
 *
 * Input shape (amounts in signed Rappen):
 *
 *     [
 *         'name' => 'Groceries',            // optional
 *         'enabled' => true,                // optional, default true
 *         'conditions' => [['field' => 'description', 'operator' => 'starts_with', 'value' => 'coop']],
 *         'actions' => [['type' => 'set_category', 'value' => 12], ['type' => 'set_payee', 'value' => 3]],
 *     ]
 *
 * A `mark_as_transfer` action takes no value: `['type' => 'mark_as_transfer']`.
 *
 * A `set_payee` action may give `name` instead of `value`: the user's payee with that name, created
 * when missing, is used.
 */
final class ManageRules
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input): Rule
    {
        $data = $this->validate($user, $input);

        return DB::transaction(function () use ($user, $data): Rule {
            $rule = new Rule([...$data, 'actions' => $this->resolveActions($user, $data['actions']), 'position' => (int) Rule::query()->ownedBy($user)->max('position') + 1]);
            $rule->user_id = $user->id;
            $rule->save();

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Rule $rule, array $input): Rule
    {
        $data = $this->validate($rule->user, $input);

        return DB::transaction(function () use ($rule, $data): Rule {
            $rule->update([...$data, 'actions' => $this->resolveActions($rule->user, $data['actions'])]);

            return $rule;
        });
    }

    public function setEnabled(Rule $rule, bool $enabled): Rule
    {
        $rule->update(['enabled' => $enabled]);

        return $rule;
    }

    public function delete(Rule $rule): void
    {
        $rule->delete();
    }

    /**
     * Put the user's rules in the given order. Ids must be exactly the user's rules.
     *
     * @param  list<int>  $ids
     */
    public function reorder(User $user, array $ids): void
    {
        $existing = Rule::query()->ownedBy($user)->pluck('id')->sort()->values()->all();
        $given = collect($ids)->map(fn (mixed $id): int => (int) $id);

        if ($given->duplicates()->isNotEmpty() || $given->sort()->values()->all() !== $existing) {
            throw ValidationException::withMessages(['order' => 'The order must list each of your rules exactly once.']);
        }

        DB::transaction(function () use ($given): void {
            $given->each(fn (int $id, int $index) => Rule::query()->whereKey($id)->update(['position' => $index + 1]));
        });
    }

    /**
     * Rules whose actions set a category or payee that is being deleted lose those actions;
     * rules whose conditions need a deleted payee can never match again. Either way a rule left
     * with nothing to do is disabled.
     *
     * @param  'category'|'payee'  $kind
     */
    public function forget(User $user, string $kind, int $id): void
    {
        $type = $kind === 'category' ? RuleActionType::SetCategory : RuleActionType::SetPayee;

        Rule::query()->ownedBy($user)->get()->filter(fn (Rule $rule): bool => $rule->references($kind, $id))->each(function (Rule $rule) use ($type, $id): void {
            $actions = array_values(array_filter($rule->actions, fn (array $action): bool => ! ($action['type'] === $type->value && (int) ($action['value'] ?? 0) === $id)));
            $deadCondition = collect($rule->conditions)->contains(fn (array $condition): bool => $condition['field'] === RuleConditionField::Payee->value && (int) $condition['value'] === $id);

            $rule->update([
                'actions' => $actions,
                'enabled' => $rule->enabled && $actions !== [] && ! ($type === RuleActionType::SetPayee && $deadCondition),
            ]);
        });
    }

    /**
     * Rules that pointed at one payee now point at another (after a merge).
     */
    public function replacePayee(User $user, int $from, int $to): void
    {
        Rule::query()->ownedBy($user)->get()->filter(fn (Rule $rule): bool => $rule->references('payee', $from))->each(function (Rule $rule) use ($from, $to): void {
            $swap = fn (array $item, string $key, string $match): array => $item[$key] === $match && (int) ($item['value'] ?? 0) === $from ? [...$item, 'value' => $to] : $item;

            $rule->update([
                'conditions' => array_map(fn (array $condition): array => $swap($condition, 'field', RuleConditionField::Payee->value), $rule->conditions),
                'actions' => array_map(fn (array $action): array => $swap($action, 'type', RuleActionType::SetPayee->value), $rule->actions),
            ]);
        });
    }

    /**
     * Validate conditions on their own, for the live preview of an unsaved rule.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{field: string, operator: string, value: string|int|list<int>}>
     *
     * @throws ValidationException
     */
    public function validateConditions(User $user, array $input): array
    {
        return $this->validate($user, $input, withActions: false)['conditions'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string|null, enabled: bool, conditions: list<array{field: string, operator: string, value: string|int|list<int>}>, actions: list<array{type: string, value?: int, name?: string}>}
     *
     * @throws ValidationException
     */
    public function validate(User $user, array $input, bool $withActions = true): array
    {
        $rules = [
            'name' => ['nullable', 'string', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'conditions' => ['required', 'array', 'min:1', 'max:20'],
            'conditions.*' => ['array'],
            'conditions.*.field' => ['required', ValidationRule::enum(RuleConditionField::class)],
            'conditions.*.operator' => ['required', ValidationRule::enum(RuleOperator::class)],
            'conditions.*.value' => ['present'],
        ];

        if ($withActions) {
            $rules += [
                'actions' => ['required', 'array', 'min:1', 'max:10'],
                'actions.*' => ['array'],
                'actions.*.type' => ['required', 'distinct', ValidationRule::enum(RuleActionType::class)],
                'actions.*.value' => ['nullable', 'integer'],
                'actions.*.name' => ['nullable', 'string', 'max:100'],
            ];
        }

        $validator = Validator::make($input, $rules, [
            'conditions.required' => 'Add at least one condition.',
            'actions.required' => 'Add at least one action.',
            'actions.*.type.distinct' => 'Each field can only be set once per rule.',
        ]);

        $validator->after(function ($validator) use ($input, $withActions, $user): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($input['conditions'] as $index => $condition) {
                $message = $this->conditionError($user, RuleConditionField::from($condition['field']), RuleOperator::from($condition['operator']), $condition['value']);

                if ($message !== null) {
                    $validator->errors()->add("conditions.{$index}.value", $message);
                }
            }

            foreach ($withActions ? $input['actions'] : [] as $index => $action) {
                $type = RuleActionType::from($action['type']);

                if ($type === RuleActionType::MarkAsTransfer) {
                    continue;
                }

                if (($action['value'] ?? null) === null) {
                    if ($type !== RuleActionType::SetPayee || trim((string) ($action['name'] ?? '')) === '') {
                        $validator->errors()->add("actions.{$index}.value", 'Choose a value.');
                    }

                    continue;
                }

                if (! $type->model()::query()->where('user_id', $user->id)->whereKey((int) $action['value'])->exists()) {
                    $validator->errors()->add("actions.{$index}.value", $type === RuleActionType::SetCategory ? 'Choose one of your categories.' : 'Choose one of your payees.');
                }
            }
        });

        $validator->validate();

        return [
            'name' => isset($input['name']) && trim((string) $input['name']) !== '' ? trim((string) $input['name']) : null,
            'enabled' => (bool) ($input['enabled'] ?? true),
            'conditions' => array_values(array_map(fn (array $condition): array => [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => $this->normalizeValue(RuleConditionField::from($condition['field']), RuleOperator::from($condition['operator']), $condition['value']),
            ], $input['conditions'])),
            'actions' => $withActions ? array_values(array_map(fn (array $action): array => match (true) {
                $action['type'] === RuleActionType::MarkAsTransfer->value => ['type' => $action['type']],
                ($action['value'] ?? null) === null => ['type' => $action['type'], 'name' => trim((string) $action['name'])],
                default => ['type' => $action['type'], 'value' => (int) $action['value']],
            }, $input['actions'])) : [],
        ];
    }

    /**
     * Turn `set_payee` actions given by name into payee ids, creating missing payees.
     *
     * @param  list<array{type: string, value?: int, name?: string}>  $actions
     * @return list<array{type: string, value?: int}>
     */
    private function resolveActions(User $user, array $actions): array
    {
        return array_map(function (array $action) use ($user): array {
            if (isset($action['value'])) {
                return ['type' => $action['type'], 'value' => $action['value']];
            }

            if ($action['type'] === RuleActionType::MarkAsTransfer->value) {
                return ['type' => $action['type']];
            }

            $payee = Payee::query()->ownedBy($user)->where('name', $action['name'] ?? '')->first();

            if ($payee === null) {
                $payee = new Payee(['name' => $action['name'] ?? '']);
                $payee->user_id = $user->id;
                $payee->save();
            }

            return ['type' => $action['type'], 'value' => $payee->id];
        }, $actions);
    }

    private function conditionError(User $user, RuleConditionField $field, RuleOperator $operator, mixed $value): ?string
    {
        if (! in_array($operator, $field->operators(), true)) {
            return "This operator does not apply to {$field->value}.";
        }

        return match ($field) {
            RuleConditionField::Description => match (true) {
                ! is_string($value) || trim($value) === '' => 'Enter some text.',
                mb_strlen($value) > 200 => 'Keep it under 200 characters.',
                $operator === RuleOperator::Regex && @preg_match(RuleEngine::pattern($value), '') === false => 'This is not a valid regular expression.',
                default => null,
            },
            RuleConditionField::Payee => $this->isInteger($value) && Payee::query()->where('user_id', $user->id)->whereKey((int) $value)->exists() ? null : 'Choose one of your payees.',
            RuleConditionField::Account => $this->isInteger($value) && Account::query()->where('user_id', $user->id)->whereKey((int) $value)->exists() ? null : 'Choose one of your accounts.',
            RuleConditionField::Amount, RuleConditionField::AbsoluteAmount => match (true) {
                $operator === RuleOperator::Between => is_array($value) && count($value) === 2 && $this->isInteger($value[0] ?? null) && $this->isInteger($value[1] ?? null)
                    ? ((int) $value[0] <= (int) $value[1] ? null : 'The first amount must not be larger than the second.')
                    : 'Enter two amounts.',
                default => $this->isInteger($value) ? null : 'Enter an amount.',
            },
        };
    }

    /**
     * @return string|int|list<int>
     */
    private function normalizeValue(RuleConditionField $field, RuleOperator $operator, mixed $value): string|int|array
    {
        return match (true) {
            $field === RuleConditionField::Description => trim((string) $value),
            $operator === RuleOperator::Between => [(int) $value[0], (int) $value[1]],
            default => (int) $value,
        };
    }

    private function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    }
}
