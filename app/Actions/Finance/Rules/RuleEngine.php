<?php

namespace App\Actions\Finance\Rules;

use App\Models\Finance\FieldSource;
use App\Models\Finance\Rule;
use App\Models\Finance\RuleActionType;
use App\Models\Finance\RuleConditionField;
use App\Models\Finance\RuleOperator;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs categorization rules on transactions. Imports, manual edits, the Rules pages and (later)
 * MCP tools all go through this class, so a rule means the same thing everywhere.
 *
 * Semantics:
 * - A rule matches when all its conditions match. Description conditions compare
 *   case-insensitively after collapsing whitespace.
 * - Every matching enabled rule applies, in position order. A later rule overrides an earlier
 *   one for the same field, and conditions see the values earlier rules set in the same run
 *   (a `payee is` condition matches a payee an earlier rule just assigned).
 * - A field whose source is manual is never changed. Rules set values but never clear them.
 */
final class RuleEngine
{
    /**
     * Enabled rules of the user, in the order they apply.
     *
     * @return Collection<int, Rule>
     */
    public function rulesFor(User $user): Collection
    {
        return Rule::query()->ownedBy($user)->where('enabled', true)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Run rules on one transaction in memory; the caller saves it. Returns the fields that
     * changed, keyed by `category` or `payee`.
     *
     * @param  iterable<Rule>  $rules  in the order they apply
     * @return array<string, array{from: int|null, to: int}>
     */
    public function run(Transaction $transaction, iterable $rules): array
    {
        $original = [];

        foreach (RuleActionType::cases() as $type) {
            $original[$type->field()] = $transaction->getAttribute($type->column());
        }

        foreach ($rules as $rule) {
            if (! $this->matches($rule->conditions, $transaction)) {
                continue;
            }

            foreach ($rule->actions as $action) {
                $type = RuleActionType::from($action['type']);

                if ($transaction->getAttribute($type->sourceColumn()) === FieldSource::Manual) {
                    continue;
                }

                $transaction->setAttribute($type->column(), (int) $action['value']);
                $transaction->setAttribute($type->sourceColumn(), FieldSource::Rule);
            }
        }

        $changes = [];

        foreach (RuleActionType::cases() as $type) {
            $to = $transaction->getAttribute($type->column());

            if ($to !== $original[$type->field()]) {
                $changes[$type->field()] = ['from' => $original[$type->field()], 'to' => (int) $to];
            }
        }

        return $changes;
    }

    /**
     * Whether every condition matches the transaction as it is in memory.
     *
     * @param  list<array{field: string, operator: string, value: mixed}>  $conditions
     */
    public function matches(array $conditions, Transaction $transaction): bool
    {
        foreach ($conditions as $condition) {
            if (! $this->conditionMatches($condition, $transaction)) {
                return false;
            }
        }

        return $conditions !== [];
    }

    /**
     * Transactions the conditions match right now, newest first: the live preview of a rule
     * being edited. Unsaved conditions are fine; validate them first with ManageRules::validate().
     *
     * @param  list<array{field: string, operator: string, value: mixed}>  $conditions
     * @return array{count: int, sample: list<Transaction>}
     */
    public function matching(User $user, array $conditions, int $sample = 20): array
    {
        $count = 0;
        $rows = [];

        foreach ($this->transactions($user)->with(['account', 'category', 'payee'])->lazy(1000) as $transaction) {
            if ($this->matches($conditions, $transaction)) {
                $count++;

                if (count($rows) < $sample) {
                    $rows[] = $transaction;
                }
            }
        }

        return ['count' => $count, 'sample' => $rows];
    }

    /**
     * Dry run of applying rules to existing transactions: what would change, without writing.
     * With `$scope`, only the transactions that rule matches are considered, but all enabled
     * rules still run on them in order, so the outcome is the same as a full run for those rows.
     *
     * @return array{transactions: int, changes: array<string, int>, sample: list<array{transaction: Transaction, changes: array<string, array{from: int|null, to: int}>}>}
     */
    public function preview(User $user, ?Rule $scope = null, int $sample = 25): array
    {
        $plan = $this->plan($user, $scope);

        return [
            'transactions' => count($plan),
            'changes' => $this->countFields($plan),
            'sample' => array_slice($plan, 0, $sample),
        ];
    }

    /**
     * Apply rules to existing transactions (see preview()). Returns the number of transactions
     * changed and the changes per field.
     *
     * @return array{transactions: int, changes: array<string, int>}
     */
    public function apply(User $user, ?Rule $scope = null): array
    {
        return DB::transaction(function () use ($user, $scope): array {
            $plan = $this->plan($user, $scope);

            foreach ($plan as $entry) {
                $entry['transaction']->save();
            }

            return ['transactions' => count($plan), 'changes' => $this->countFields($plan)];
        });
    }

    /**
     * Lowercase, trimmed, with every run of whitespace collapsed to one space.
     */
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /**
     * The PCRE for a user's pattern: case-insensitive, Unicode, delimited by a control character
     * so the pattern needs no escaping of `/` or `#`.
     */
    public static function pattern(string $regex): string
    {
        return "\x01{$regex}\x01iu";
    }

    /**
     * @return list<array{transaction: Transaction, changes: array<string, array{from: int|null, to: int}>}>
     */
    private function plan(User $user, ?Rule $scope): array
    {
        $rules = $this->rulesFor($user);
        $plan = [];

        foreach ($this->transactions($user)->with(['account'])->lazy(1000) as $transaction) {
            if ($scope !== null && ! $this->matches($scope->conditions, $transaction)) {
                continue;
            }

            $changes = $this->run($transaction, $rules);

            if ($changes !== []) {
                $plan[] = ['transaction' => $transaction, 'changes' => $changes];
            }
        }

        return $plan;
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactions(User $user): Builder
    {
        return Transaction::query()->ownedBy($user)->orderByDesc('date')->orderByDesc('time')->orderByDesc('id');
    }

    /**
     * @param  list<array{transaction: Transaction, changes: array<string, mixed>}>  $plan
     * @return array<string, int>
     */
    private function countFields(array $plan): array
    {
        $counts = array_fill_keys(array_map(fn (RuleActionType $type): string => $type->field(), RuleActionType::cases()), 0);

        foreach ($plan as $entry) {
            foreach (array_keys($entry['changes']) as $field) {
                $counts[$field]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array{field: string, operator: string, value: mixed}  $condition
     */
    private function conditionMatches(array $condition, Transaction $transaction): bool
    {
        $operator = RuleOperator::from($condition['operator']);
        $value = $condition['value'];

        return match (RuleConditionField::from($condition['field'])) {
            RuleConditionField::Description => $this->textMatches($operator, self::normalize($transaction->description), (string) $value),
            RuleConditionField::Payee => $transaction->payee_id === (int) $value,
            RuleConditionField::Account => $transaction->account_id === (int) $value,
            RuleConditionField::Amount => $this->amountMatches($operator, $transaction->amount, $value),
            RuleConditionField::AbsoluteAmount => $this->amountMatches($operator, abs($transaction->amount), $value),
        };
    }

    private function textMatches(RuleOperator $operator, string $description, string $value): bool
    {
        return match ($operator) {
            RuleOperator::Contains => str_contains($description, self::normalize($value)),
            RuleOperator::Equals => $description === self::normalize($value),
            RuleOperator::StartsWith => str_starts_with($description, self::normalize($value)),
            RuleOperator::Regex => @preg_match(self::pattern($value), $description) === 1,
            default => false,
        };
    }

    private function amountMatches(RuleOperator $operator, int $amount, mixed $value): bool
    {
        return match ($operator) {
            RuleOperator::Equals => $amount === (int) $value,
            RuleOperator::GreaterThan => $amount > (int) $value,
            RuleOperator::LessThan => $amount < (int) $value,
            RuleOperator::Between => is_array($value) && $amount >= (int) $value[0] && $amount <= (int) $value[1],
            default => false,
        };
    }
}
