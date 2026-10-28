<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Rules\ManageRules;
use App\Models\Finance\Rule;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_rule')]
#[Description(<<<'TEXT'
Replace a rule's name, enabled flag, conditions and actions (its position stays). Send the whole
rule: conditions and actions are required and replace the old ones; an omitted name clears it and
an omitted enabled means true. Saving changes no transaction: run preview_apply_rules and
apply_rules afterwards.
TEXT)]
#[IsIdempotent]
class UpdateRuleTool extends FinanceTool
{
    public function __construct(private ManageRules $rules) {}

    public function description(): string
    {
        return parent::description()."\n\n".RuleSchema::reference();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_id' => $schema->integer()->description('The rule id (from list_rules).')->required(),
            'name' => $schema->string()->max(100)->description('Optional rule name.'),
            'enabled' => $schema->boolean()->default(true)->description('Whether the rule runs. Default true.'),
            'conditions' => RuleSchema::conditions($schema)->required(),
            'actions' => RuleSchema::actions($schema)->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['rule_id' => ['required', 'integer']]);
        $rule = Rule::query()->ownedBy($user)->findOrFail((int) $data['rule_id']);

        return Response::structured(['rule' => Present::rule($this->rules->update($rule, Arr::except($request->all(), ['rule_id'])))]);
    }
}
