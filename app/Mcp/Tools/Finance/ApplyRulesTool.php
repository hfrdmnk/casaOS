<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Rules\RuleEngine;
use App\Models\Finance\Rule;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('apply_rules')]
#[Description(<<<'TEXT'
Apply the enabled rules to existing transactions and save the result: exactly what
preview_apply_rules with the same arguments shows. Without `rule_id` all transactions; with it,
only the ones that rule matches (all enabled rules still run on them). Values set by hand are never
changed, and running it twice changes nothing the second time. Returns the number of transactions
changed and the changes per field.
TEXT)]
#[IsIdempotent]
class ApplyRulesTool extends FinanceTool
{
    public function __construct(private RuleEngine $engine) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->integer()->description('Limit to the transactions this rule matches. Omit for all rules on all transactions.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['rule_id' => ['nullable', 'integer']]);
        $rule = isset($data['rule_id']) ? Rule::query()->ownedBy($user)->findOrFail((int) $data['rule_id']) : null;

        return Response::structured(['rule_id' => $rule?->id, ...$this->engine->apply($user, $rule)]);
    }
}
