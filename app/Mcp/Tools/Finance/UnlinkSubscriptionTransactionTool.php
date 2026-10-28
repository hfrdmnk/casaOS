<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Subscriptions\ManageSubscriptions;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('unlink_subscription_transaction')]
#[Description('Unlink a transaction from its subscription by hand. It is never linked automatically again; the subscription\'s next date is recomputed from its remaining charges.')]
#[IsIdempotent]
class UnlinkSubscriptionTransactionTool extends FinanceTool
{
    public function __construct(private ManageSubscriptions $subscriptions) {}

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
        $subscription = $transaction->subscription;
        $this->subscriptions->unlink($transaction);

        return Response::structured([
            'transaction' => Present::transaction($transaction->refresh()),
            'subscription' => $subscription === null ? null : Present::subscription($subscription->refresh(), payments: false),
        ]);
    }
}
