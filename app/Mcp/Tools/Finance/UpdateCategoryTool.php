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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_category')]
#[Description('Rename a category. Its transactions and the rules that set it keep pointing at it. Names are unique per user.')]
#[IsIdempotent]
class UpdateCategoryTool extends FinanceTool
{
    public function __construct(private ManageCategories $categories) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id' => $schema->integer()->description('The category id.')->required(),
            'name' => $schema->string()->max(100)->description('The new name.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['category_id' => ['required', 'integer'], 'name' => ['required', 'string']]);
        $category = Category::query()->ownedBy($user)->findOrFail((int) $data['category_id']);

        return Response::structured([
            'category' => Present::category($this->categories->rename($category, $data['name'])),
        ]);
    }
}
