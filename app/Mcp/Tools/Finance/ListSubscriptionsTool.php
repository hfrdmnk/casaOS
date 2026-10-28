<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Subscriptions\ManageSubscriptions;
use App\Models\Finance\Subscription;
use App\Models\Finance\SubscriptionStatus;
use App\Models\Finance\SubscriptionSuggestion;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_subscriptions')]
#[Description(<<<'TEXT'
List the user's subscriptions (recurring payments), soonest next date first, and the open
subscription suggestions detection found. A subscription matches charges by `payee_id`, or without
one by `description_match` (contained in the lowercased, letters-only description), in `account_id`
when set, within ±10% of `amount`. `amount` is the expected charge (positive Rappen), `interval`
weekly/monthly/quarterly/yearly, `monthly_amount` its average cost per month, `next_date` the day
the next charge is expected, `status` active or cancelled, `source` manual or detected. `payments`
are the linked charges, newest first; `monthly_total` sums the active ones. Confirm or dismiss
suggestions with confirm_subscription_suggestion / dismiss_subscription_suggestion.
TEXT)]
#[IsReadOnly]
class ListSubscriptionsTool extends FinanceTool
{
    public function __construct(private ManageSubscriptions $subscriptions) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['active', 'cancelled'])->description('Only subscriptions with this status. Omit for all.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['status' => ['nullable', 'in:active,cancelled']]);
        $all = Subscription::query()->ownedBy($user)->orderBy('next_date')->orderBy('name')->get();

        return Response::structured([
            'subscriptions' => $all->when($data['status'] ?? null, fn ($all, string $status) => $all->where('status', SubscriptionStatus::from($status)))
                ->map(fn (Subscription $subscription): array => Present::subscription($subscription))->values()->all(),
            ...Present::money('monthly_total', $all->where('status', SubscriptionStatus::Active)->sum(fn (Subscription $subscription): int => $subscription->monthlyAmount())),
            'suggestions' => $this->subscriptions->suggestions($user)
                ->map(fn (SubscriptionSuggestion $suggestion): array => Present::subscriptionSuggestion($suggestion))->all(),
        ]);
    }
}
