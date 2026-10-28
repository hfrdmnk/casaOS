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

#[Name('unmark_transfer')]
#[Description('Mark a transaction as not a transfer: it counts as income or spending again and is never marked or paired automatically again. If it was paired, the counterpart loses the pair (and stops being a transfer when only automatic pairing had made it one).')]
class UnmarkTransferTool extends FinanceTool
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
        $this->transfers->unmark($transaction);

        return Response::structured([
            'transaction' => Present::transaction($transaction->refresh()),
            'former_counterpart' => $counterpart === null ? null : Present::transaction(Transaction::query()->findOrFail($counterpart)),
        ]);
    }
}
