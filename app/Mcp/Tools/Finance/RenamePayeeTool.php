<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Payees\ManagePayees;
use App\Models\Finance\Payee;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('rename_payee')]
#[Description('Rename a payee. Its transactions and rules keep pointing at it. If another payee already has the name, use merge_payees instead.')]
#[IsIdempotent]
class RenamePayeeTool extends FinanceTool
{
    public function __construct(private ManagePayees $payees) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'payee_id' => $schema->integer()->description('The payee id.')->required(),
            'name' => $schema->string()->max(100)->description('The new name.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['payee_id' => ['required', 'integer'], 'name' => ['required', 'string']]);
        $payee = Payee::query()->ownedBy($user)->findOrFail((int) $data['payee_id']);

        return Response::structured([
            'payee' => Present::payee($this->payees->rename($payee, $data['name'])),
        ]);
    }
}
