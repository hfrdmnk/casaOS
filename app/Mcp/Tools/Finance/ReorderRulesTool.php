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

#[Name('reorder_rules')]
#[Description('Set the order rules apply in. `rule_ids` must list every one of the user\'s rules exactly once (get them from list_rules), first to last. Later rules override earlier ones for the same field, so put specific rules after general ones. Reordering changes no transaction until rules run again.')]
#[IsIdempotent]
class ReorderRulesTool extends FinanceTool
{
    public function __construct(private ManageRules $rules) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'rule_ids' => $schema->array()->items($schema->integer())->description('Every rule id, in the new order.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['rule_ids' => ['required', 'array'], 'rule_ids.*' => ['integer']]);
        $this->rules->reorder($user, $data['rule_ids']);

        return Response::structured([
            'rules' => Rule::query()->ownedBy($user)->orderBy('position')->orderBy('id')->get()
                ->map(fn (Rule $rule): array => Present::rule($rule))->all(),
        ]);
    }
}
