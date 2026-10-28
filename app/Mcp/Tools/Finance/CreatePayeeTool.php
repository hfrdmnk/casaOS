<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Payees\ManagePayees;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_payee')]
#[Description('Create a payee. Names are unique per user (trimmed, at most 100 characters). A `set_payee` rule action can also create a payee by name, so this is only needed for hand-set payees.')]
#[IsDestructive(false)]
class CreatePayeeTool extends FinanceTool
{
    public function __construct(private ManagePayees $payees) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(100)->description('The payee name, e.g. "Migros".')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['name' => ['required', 'string']]);

        return Response::structured([
            'payee' => Present::payee($this->payees->create($user, $data['name'])),
        ]);
    }
}
