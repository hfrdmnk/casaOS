<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Rules\ManageRules;
use App\Models\Finance\Rule;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('set_rule_enabled')]
#[Description('Enable or disable a rule. A disabled rule keeps its place in the order but does not run on imports or apply_rules.')]
#[IsIdempotent]
class SetRuleEnabledTool extends FinanceTool
{
    public function __construct(private ManageRules $rules) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->integer()->description('The rule id.')->required(),
            'enabled' => $schema->boolean()->description('true to enable, false to disable.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['rule_id' => ['required', 'integer'], 'enabled' => ['required', 'boolean']]);
        $rule = Rule::query()->ownedBy($user)->findOrFail((int) $data['rule_id']);

        return Response::structured(['rule' => Present::rule($this->rules->setEnabled($rule, (bool) $data['enabled']))]);
    }
}
