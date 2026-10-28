<?php

namespace App\Models\Finance;

/**
 * What a rule condition looks at, and which operators it supports. Amounts are signed Rappen
 * (net of fees); `absolute_amount` compares the amount without its sign.
 */
enum RuleConditionField: string
{
    case Description = 'description';
    case Payee = 'payee';
    case Amount = 'amount';
    case AbsoluteAmount = 'absolute_amount';
    case Account = 'account';

    /**
     * @return list<RuleOperator>
     */
    public function operators(): array
    {
        return match ($this) {
            self::Description => [RuleOperator::Contains, RuleOperator::Equals, RuleOperator::StartsWith, RuleOperator::Regex],
            self::Payee, self::Account => [RuleOperator::Is],
            self::Amount, self::AbsoluteAmount => [RuleOperator::Equals, RuleOperator::GreaterThan, RuleOperator::LessThan, RuleOperator::Between],
        };
    }
}
