<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Subscriptions\ManageSubscriptions;
use App\Models\Finance\SubscriptionSuggestion;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('confirm_subscription_suggestion')]
#[Description('Confirm a subscription suggestion: it becomes an active subscription (source `detected`) with its charges and any other matching charges linked, and its next date one interval after the newest one. Returns the subscription.')]
#[IsDestructive(false)]
class ConfirmSubscriptionSuggestionTool extends FinanceTool
{
    public function __construct(private ManageSubscriptions $subscriptions) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'suggestion_id' => $schema->integer()->description('The suggestion id (from list_subscriptions `suggestions`).')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['suggestion_id' => ['required', 'integer']]);
        $suggestion = SubscriptionSuggestion::query()->ownedBy($user)->whereNull('dismissed_at')->findOrFail((int) $data['suggestion_id']);

        return Response::structured(['subscription' => Present::subscription($this->subscriptions->confirm($suggestion))]);
    }
}
