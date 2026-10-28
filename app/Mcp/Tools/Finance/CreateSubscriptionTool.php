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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_subscription')]
#[Description(<<<'TEXT'
Create a subscription by hand (source `manual`). With `transaction_id` this is "Mark as
subscription": the name, payee (or else the description), account and amount come from that charge
and the interval is monthly; any field you give overrides them. Without it, give name, amount,
interval and payee_id or description_match. Every matching charge is linked (also pending ones) and
`next_date` becomes one interval after the newest, unless you give it. Returns the subscription
with its payments.
TEXT)]
#[IsDestructive(false)]
class CreateSubscriptionTool extends FinanceTool
{
    public function __construct(private ManageSubscriptions $subscriptions) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->description('Create it from this charge ("Mark as subscription").'),
            ...SubscriptionSchema::fields($schema),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $request->validate(['transaction_id' => ['nullable', 'integer']]);
        $fields = SubscriptionSchema::only($request->all());
        unset($fields['status']);

        $subscription = $request->get('transaction_id') !== null
            ? $this->subscriptions->createFromTransaction(Transaction::query()->ownedBy($user)->findOrFail((int) $request->get('transaction_id')), $fields)
            : $this->subscriptions->create($user, $fields);

        return Response::structured(['subscription' => Present::subscription($subscription)]);
    }
}
