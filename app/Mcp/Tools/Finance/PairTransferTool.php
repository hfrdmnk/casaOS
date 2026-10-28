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

#[Name('pair_transfer')]
#[Description('Pair two transactions by hand as one transfer: money leaving one account and arriving in another (opposite signs, different accounts; amounts need not match exactly). Neither may be paired already (unpair_transfer first). Both become transfers with source manual and lose their category. Find candidates with transfer_candidates.')]
class PairTransferTool extends FinanceTool
{
    public function __construct(private ManageTransfers $transfers) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->description('One side of the transfer.')->required(),
            'counterpart_id' => $schema->integer()->description('The other side, in another account.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['transaction_id' => ['required', 'integer'], 'counterpart_id' => ['required', 'integer']]);
        $transaction = Transaction::query()->ownedBy($user)->findOrFail((int) $data['transaction_id']);
        $counterpart = Transaction::query()->ownedBy($user)->findOrFail((int) $data['counterpart_id']);
        $this->transfers->pair($transaction, $counterpart);

        return Response::structured([
            'transaction' => Present::transaction($transaction->refresh()),
            'counterpart' => Present::transaction($counterpart->refresh()),
        ]);
    }
}
