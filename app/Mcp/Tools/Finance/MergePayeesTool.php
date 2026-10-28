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

#[Name('merge_payees')]
#[Description('Merge one payee into another: every transaction and rule of `payee_id` moves to `into_payee_id`, then `payee_id` is deleted. Use it for duplicates such as "Coop" and "COOP Pronto". Cannot be undone.')]
#[IsDestructive]
class MergePayeesTool extends FinanceTool
{
    public function __construct(private ManagePayees $payees) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'payee_id' => $schema->integer()->description('The payee to merge away (it is deleted).')->required(),
            'into_payee_id' => $schema->integer()->description('The payee that remains and receives the transactions and rules.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['payee_id' => ['required', 'integer'], 'into_payee_id' => ['required', 'integer']]);
        $from = Payee::query()->ownedBy($user)->findOrFail((int) $data['payee_id']);
        $into = Payee::query()->ownedBy($user)->findOrFail((int) $data['into_payee_id']);
        $moved = $this->payees->merge($from, $into);

        return Response::structured([
            'payee' => Present::payee($into),
            'transactions_moved' => $moved,
        ]);
    }
}
