<?php

namespace App\Mcp\Tools\Finance;

use App\Models\Finance\RuleActionType;
use App\Models\Finance\RuleConditionField;
use App\Models\Finance\RuleOperator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The input schema and reference text for rule conditions and actions, built from the enums the
 * RuleEngine uses, so what an agent reads is what ManageRules validates.
 */
final class RuleSchema
{
    public static function conditions(JsonSchema $schema): Type
    {
        return $schema->array()->min(1)->max(20)->items($schema->object([
            'field' => $schema->string()->enum(array_column(RuleConditionField::cases(), 'value'))->required(),
            'operator' => $schema->string()->enum(array_column(RuleOperator::cases(), 'value'))->required(),
            'value' => $schema->anyOf([
                $schema->string()->description('Text, for description conditions.'),
                $schema->integer()->description('An id (payee, account) or an amount in signed Rappen.'),
                $schema->array()->items($schema->integer())->min(2)->max(2)->description('[low, high] in Rappen, for between.'),
            ])->required(),
        ]))->description('All conditions must match (AND). See the condition reference in the tool description.');
    }

    public static function actions(JsonSchema $schema): Type
    {
        return $schema->array()->min(1)->max(10)->items($schema->object([
            'type' => $schema->string()->enum(array_column(RuleActionType::cases(), 'value'))->required(),
            'value' => $schema->integer()->description('The category id (set_category) or payee id (set_payee). Omit for mark_as_transfer.'),
            'name' => $schema->string()->max(100)->description('set_payee only, instead of value: the payee with this name, created when missing.'),
        ]))->description('What a matching rule does; each type at most once. See the action reference in the tool description.');
    }

    /**
     * The condition and action reference, for tool descriptions and the categorization prompt.
     */
    public static function reference(): string
    {
        $operators = fn (RuleConditionField $field): string => implode(' | ', array_map(fn (RuleOperator $operator): string => $operator->value, $field->operators()));

        return <<<TEXT
        Conditions: a list of {field, operator, value}; a rule matches when ALL its conditions match.
        - description ({$operators(RuleConditionField::Description)}): value is text, compared case-insensitively with the raw bank description after collapsing whitespace. regex is a PCRE pattern without delimiters (e.g. "^(coop|migros)\\b"), case-insensitive.
        - payee ({$operators(RuleConditionField::Payee)}): value is a payee id.
        - account ({$operators(RuleConditionField::Account)}): value is an account id.
        - amount ({$operators(RuleConditionField::Amount)}): value is signed Rappen, net of fees; spending is negative (CHF -12.50 is -1250). between takes [low, high], inclusive, low <= high (e.g. [-5000, -1000] for spending between CHF 10 and 50).
        - absolute_amount (same operators): like amount but compared without the sign (between [1000, 5000] for CHF 10 to 50 either way).
        Actions: a list, at least one, each type at most once.
        - {"type": "set_category", "value": <category id>}
        - {"type": "set_payee", "value": <payee id>} or {"type": "set_payee", "name": "Migros"} (uses the payee with that name, creating it)
        - {"type": "mark_as_transfer"} (no value): marks it a transfer without a counterpart and clears its category; transfer pairing may find the counterpart later.
        Semantics: every matching enabled rule applies, in position order; a later rule overrides an earlier one for the same field, and conditions see values earlier rules set in the same run. A category or payee set by hand (source `manual`) is never changed. Rules set values but never clear them. Saving a rule changes no transaction: run preview_apply_rules, then apply_rules. Imports run the rules on new rows automatically.
        TEXT;
    }
}
