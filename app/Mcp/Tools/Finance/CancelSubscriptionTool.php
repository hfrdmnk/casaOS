<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Subscriptions\ManageSubscriptions;
use App\Models\Finance\Subscription;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('cancel_subscription')]
#[Description('Cancel a subscription: it keeps its payment history but links no new charges, is not upcoming and leaves the monthly total. Reactivate with update_subscription status active.')]
#[IsIdempotent]
class CancelSubscriptionTool extends FinanceTool
{
    public function __construct(private ManageSubscriptions $subscriptions) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'subscription_id' => $schema->integer()->description('The subscription id (from list_subscriptions).')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['subscription_id' => ['required', 'integer']]);
        $subscription = Subscription::query()->ownedBy($user)->findOrFail((int) $data['subscription_id']);

        return Response::structured(['subscription' => Present::subscription($this->subscriptions->cancel($subscription), payments: false)]);
    }
}
