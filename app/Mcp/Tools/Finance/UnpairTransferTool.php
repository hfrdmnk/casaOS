<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Transfers\ManageTransfers;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('unpair_transfer')]
#[Description('Unpair a transfer pair by hand. Both sides stay transfers without a counterpart (so neither counts as income or spending) and are never paired automatically again; unmark_transfer one of them to make it a plain transaction, or pair_transfer it with the right counterpart.')]
class UnpairTransferTool extends FinanceTool
{
    public function __construct(private ManageTransfers $transfers) {}

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
        $counterpart = $transaction->transfer_counterpart_id;
        $this->transfers->unpair($transaction);

        return Response::structured([
            'transaction' => Present::transaction($transaction->refresh()),
            'former_counterpart' => $counterpart === null ? null : Present::transaction(Transaction::query()->findOrFail($counterpart)),
        ]);
    }
}
