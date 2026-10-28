<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Categories\ManageCategories;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_category')]
#[Description('Create a category. Names are unique per user (case-sensitive, trimmed, at most 100 characters). Check list_categories first: the user starts with defaults such as Groceries, Dining, Transport and Income. Returns the new category with its id.')]
#[IsDestructive(false)]
class CreateCategoryTool extends FinanceTool
{
    public function __construct(private ManageCategories $categories) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(100)->description('The category name, e.g. "Pets".')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['name' => ['required', 'string']]);

        return Response::structured([
            'category' => Present::category($this->categories->create($user, $data['name'])),
        ]);
    }
}
