<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Rules\ManageRules;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_rule')]
#[Description(<<<'TEXT'
Create a categorization rule at the end of the rule order. Test the conditions with
preview_rule_matches first. Saving changes no transaction: afterwards run preview_apply_rules with
the new rule_id, show the user the changes, then apply_rules.
TEXT)]
#[IsDestructive(false)]
class CreateRuleTool extends FinanceTool
{
    public function __construct(private ManageRules $rules) {}

    public function description(): string
    {
        return parent::description()."\n\n".RuleSchema::reference();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(100)->description('Optional name shown in the rule list, e.g. "Groceries: Migros".'),
            'enabled' => $schema->boolean()->default(true)->description('Whether the rule runs. Default true.'),
            'conditions' => RuleSchema::conditions($schema)->required(),
            'actions' => RuleSchema::actions($schema)->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $rule = $this->rules->create($user, $request->all());

        return Response::structured(['rule' => Present::rule($rule)]);
    }
}
