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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('dismiss_subscription_suggestion')]
#[Description('Dismiss a subscription suggestion for good: the same payee or description is never suggested again at that interval. No transaction changes.')]
#[IsIdempotent]
class DismissSubscriptionSuggestionTool extends FinanceTool
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
        $this->subscriptions->dismiss($suggestion);

        return Response::structured(['message' => 'Suggestion dismissed.', 'suggestion' => Present::subscriptionSuggestion($suggestion)]);
    }
}
