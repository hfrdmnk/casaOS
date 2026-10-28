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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('transfer_candidates')]
#[Description('Transactions this one could be paired with by pair_transfer: unpaired, in another account, with the opposite sign. Without `search`: the pairing candidates (exactly the opposite amount, within 3 days), closest first. With `search`: any such transaction whose description or payee contains the text, or whose amount is the given CHF amount (e.g. "250" or "250.50"), closest in date first.')]
#[IsReadOnly]
class TransferCandidatesTool extends FinanceTool
{
    public function __construct(private ManageTransfers $transfers) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->description('The transaction to find a counterpart for.')->required(),
            'search' => $schema->string()->max(200)->description('Optional text or CHF amount to search for instead of the automatic candidates.'),
            'limit' => $schema->integer()->min(1)->max(50)->default(20)->description('At most this many candidates, up to 50.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'integer'],
            'search' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $transaction = Transaction::query()->ownedBy($user)->findOrFail((int) $data['transaction_id']);

        return Response::structured([
            'transaction' => Present::transaction($transaction),
            'candidates' => $this->transfers->candidates($transaction, $data['search'] ?? null, (int) ($data['limit'] ?? 20))
                ->map(fn (Transaction $candidate): array => Present::transaction($candidate))->all(),
        ]);
    }
}
