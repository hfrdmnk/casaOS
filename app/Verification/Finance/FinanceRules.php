<?php

namespace App\Verification\Finance;

use App\Actions\Finance\Rules\ManageRules;
use App\Actions\Finance\Rules\RuleEngine;
use App\Models\User;
use App\Verification\Contracts\ScenarioState;

/**
 * The finance-imported state plus one rule (`description contains migros` → Groceries, payee
 * Migros) applied to it, so payees, rule edits and the manual-wins semantics can be driven without
 * creating rules first.
 */
class FinanceRules implements ScenarioState
{
    public function __construct(private FinanceImported $imported, private ManageRules $rules, private RuleEngine $engine) {}

    public function name(): string
    {
        return 'finance-rules';
    }

    public function description(): string
    {
        return 'finance-imported plus a rule "Migros" (description contains migros → Groceries, payee Migros), applied: Migros is categorized by the rule.';
    }

    public function arrange(User $user): array
    {
        $values = $user->accounts()->exists() ? [] : $this->imported->arrange($user);

        $rule = $this->rules->create($user, [
            'name' => 'Migros',
            'conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => 'migros']],
            'actions' => [
                ['type' => 'set_category', 'value' => $user->categories()->where('name', 'Groceries')->value('id')],
                ['type' => 'set_payee', 'name' => 'Migros'],
            ],
        ]);

        return [
            ...$values,
            'rule_id' => $rule->id,
            'payee_id' => $rule->actions[1]['value'] ?? null,
            'applied' => $this->engine->apply($user),
        ];
    }
}
