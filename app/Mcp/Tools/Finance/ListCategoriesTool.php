<?php

namespace App\Mcp\Tools\Finance;

use App\Models\Finance\Category;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_categories')]
#[Description('List the user\'s categories by name, each with its id and how many transactions it has. Use the ids in update_transaction, in `set_category` rule actions and in the `category` filter of list_transactions.')]
#[IsReadOnly]
class ListCategoriesTool extends FinanceTool
{
    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return Response::structured([
            'categories' => Category::query()->ownedBy($user)->withCount('transactions')->orderBy('name')->get()
                ->map(fn (Category $category): array => Present::category($category))->all(),
        ]);
    }
}
