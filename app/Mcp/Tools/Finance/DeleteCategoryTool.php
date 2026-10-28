<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Categories\ManageCategories;
use App\Models\Finance\Category;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_category')]
#[Description('Delete a category. Its transactions become uncategorized (and their category goes back to the rules on the next apply); rules lose the actions that set it, and a rule left with no action is disabled. Cannot be undone.')]
#[IsDestructive]
class DeleteCategoryTool extends FinanceTool
{
    public function __construct(private ManageCategories $categories) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id' => $schema->integer()->description('The category id.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['category_id' => ['required', 'integer']]);
        $category = Category::query()->ownedBy($user)->withCount('transactions')->findOrFail((int) $data['category_id']);
        $this->categories->delete($category);

        return Response::structured([
            'deleted' => ['id' => $category->id, 'name' => $category->name],
            'uncategorized_transactions' => $category->transactions_count,
        ]);
    }
}
