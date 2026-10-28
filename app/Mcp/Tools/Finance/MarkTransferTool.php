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

#[Name('mark_transfer')]
#[Description('Mark a transaction as a transfer without a counterpart, e.g. money moved to an account casaOS does not track. It stops counting as income or spending, loses its category, and is never changed by rules or automatic pairing again. Fails on a paired transaction.')]
class MarkTransferTool extends FinanceTool
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
        $this->transfers->markWithoutCounterpart($transaction);

        return Response::structured([
            'transaction' => Present::transaction($transaction->refresh()),
            'former_counterpart' => $counterpart === null ? null : Present::transaction(Transaction::query()->findOrFail($counterpart)),
        ]);
    }
}
