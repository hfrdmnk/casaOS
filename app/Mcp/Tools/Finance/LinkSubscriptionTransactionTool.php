<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Subscriptions\ManageSubscriptions;
use App\Models\Finance\Subscription;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('link_subscription_transaction')]
#[Description('Link a transaction to a subscription by hand (moving it from another subscription), e.g. a charge that did not match. A manual link is final. The next date moves to one interval after the newest linked charge. Transfers cannot be linked.')]
#[IsIdempotent]
class LinkSubscriptionTransactionTool extends FinanceTool
{
    public function __construct(private ManageSubscriptions $subscriptions) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'subscription_id' => $schema->integer()->description('The subscription id.')->required(),
            'transaction_id' => $schema->integer()->description('The transaction id.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['subscription_id' => ['required', 'integer'], 'transaction_id' => ['required', 'integer']]);
        $subscription = Subscription::query()->ownedBy($user)->findOrFail((int) $data['subscription_id']);
        $transaction = Transaction::query()->ownedBy($user)->findOrFail((int) $data['transaction_id']);
        $this->subscriptions->link($subscription, $transaction);

        return Response::structured(['subscription' => Present::subscription($subscription->refresh())]);
    }
}
