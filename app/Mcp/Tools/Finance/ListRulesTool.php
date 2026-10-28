<?php

namespace App\Mcp\Tools\Finance;

use App\Models\Finance\Rule;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_rules')]
#[Description('List the user\'s categorization rules in the order they apply (by `position`), with their conditions and actions (see create_rule for the format) and whether they are enabled. Check it before creating a rule, to avoid duplicates and to see which later rule would override yours.')]
#[IsReadOnly]
class ListRulesTool extends FinanceTool
{
    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return Response::structured([
            'rules' => Rule::query()->ownedBy($user)->orderBy('position')->orderBy('id')->get()
                ->map(fn (Rule $rule): array => Present::rule($rule))->all(),
        ]);
    }
}
