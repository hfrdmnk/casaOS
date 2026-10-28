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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_rule')]
#[Description('Delete a rule. Values it already set on transactions stay (source `rule`) until another rule or the user changes them. To pause a rule instead, use set_rule_enabled.')]
#[IsDestructive]
class DeleteRuleTool extends FinanceTool
{
    public function __construct(private ManageRules $rules) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->integer()->description('The rule id.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['rule_id' => ['required', 'integer']]);
        $rule = Rule::query()->ownedBy($user)->findOrFail((int) $data['rule_id']);
        $this->rules->delete($rule);

        return Response::structured(['deleted' => Present::rule($rule)]);
    }
}
