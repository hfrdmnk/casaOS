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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('preview_apply_rules')]
#[Description(<<<'TEXT'
Dry run of apply_rules: what applying the rules to existing transactions would change, without
writing anything. Without `rule_id`, all enabled rules run on every transaction. With `rule_id`,
only the transactions that rule matches are considered, but all enabled rules still run on them in
order, so the result equals a full run for those rows. Returns the number of transactions that
would change, the count of changes per field (`category`, `payee`, `transfer`) and a sample with
each change's old and new value (ids and names). Values set by hand are never changed.
TEXT)]
#[IsReadOnly]
class PreviewApplyRulesTool extends FinanceTool
{
    public function __construct(private RuleEngine $engine) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->integer()->description('Limit to the transactions this rule matches. Omit for all rules on all transactions.'),
            'sample' => $schema->integer()->min(0)->max(100)->default(25)->description('How many changed transactions to return, at most 100.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['rule_id' => ['nullable', 'integer'], 'sample' => ['nullable', 'integer', 'min:0', 'max:100']]);
        $rule = isset($data['rule_id']) ? Rule::query()->ownedBy($user)->findOrFail((int) $data['rule_id']) : null;
        $preview = $this->engine->preview($user, $rule, (int) ($data['sample'] ?? 25));
        $names = Present::names($user);

        return Response::structured([
            'rule_id' => $rule?->id,
            'transactions' => $preview['transactions'],
            'changes' => $preview['changes'],
            'sample' => array_map(fn (array $entry): array => [
                'transaction' => Present::transaction($entry['transaction']->refresh()),
                'changes' => Present::changes($names, $entry['changes']),
            ], $preview['sample']),
        ]);
    }
}
