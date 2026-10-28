<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Transactions\FilterTransactions;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_transaction')]
#[Description(<<<'TEXT'
Get one transaction by id, with the same fields as list_transactions (amount in signed Rappen,
category and payee with their source, transfer state and counterpart, `suggested_transfer`).
TEXT)]
#[IsReadOnly]
class GetTransactionTool extends FinanceTool
{
    public function __construct(private FilterTransactions $filter) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->description('The transaction id.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['transaction_id' => ['required', 'integer']]);
        $transaction = Transaction::query()->ownedBy($user)->findOrFail((int) $data['transaction_id']);

        return Response::structured([
            'transaction' => [
                ...Present::transaction($transaction),
                'suggested_transfer' => $this->filter->inSuggestion($user, [$transaction->id]) !== [],
            ],
        ]);
    }
}
