<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Rules\ManageRules;
use App\Actions\Finance\Rules\RuleEngine;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('preview_rule_matches')]
#[Description(<<<'TEXT'
Test rule conditions without saving anything: how many of the user's transactions they match right
now, and a sample of them (newest first). This is the web rule form's live preview. Use it to tune
conditions before create_rule, e.g. to check a description pattern catches every Migros row and
nothing else. Conditions use the same format as create_rule.
TEXT)]
#[IsReadOnly]
class PreviewRuleMatchesTool extends FinanceTool
{
    public function __construct(private ManageRules $rules, private RuleEngine $engine) {}

    public function description(): string
    {
        return parent::description()."\n\n".RuleSchema::reference();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conditions' => RuleSchema::conditions($schema)->required(),
            'sample' => $schema->integer()->min(0)->max(50)->default(20)->description('How many matching transactions to return, at most 50.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['sample' => ['nullable', 'integer', 'min:0', 'max:50']]);
        $result = $this->engine->matching($user, $this->rules->validateConditions($user, $request->all()), (int) ($data['sample'] ?? 20));

        return Response::structured([
            'count' => $result['count'],
            'sample' => array_map(fn (Transaction $transaction): array => Present::transaction($transaction), $result['sample']),
        ]);
    }
}
