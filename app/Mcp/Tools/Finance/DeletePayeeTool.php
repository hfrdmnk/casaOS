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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete_payee')]
#[Description('Delete a payee. Its transactions lose the payee (it goes back to the rules); rules lose the actions that set it, rules with a `payee is` condition on it are disabled, and a rule left with no action is disabled. Cannot be undone.')]
#[IsDestructive]
class DeletePayeeTool extends FinanceTool
{
    public function __construct(private ManagePayees $payees) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'payee_id' => $schema->integer()->description('The payee id.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['payee_id' => ['required', 'integer']]);
        $payee = Payee::query()->ownedBy($user)->withCount('transactions')->findOrFail((int) $data['payee_id']);
        $this->payees->delete($payee);

        return Response::structured([
            'deleted' => ['id' => $payee->id, 'name' => $payee->name],
            'transactions_without_payee' => $payee->transactions_count,
        ]);
    }
}
