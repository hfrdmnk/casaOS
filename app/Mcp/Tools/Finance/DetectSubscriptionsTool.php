<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Subscriptions\ManageSubscriptions;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('detect_subscriptions')]
#[Description(<<<'TEXT'
"Detect subscriptions" across all the user's charges and rebuild the open suggestions. Completed
outflows that are not transfers and not linked to a subscription are grouped by payee (or by
lowercased, letters-only description without one). A group is suggested when at least 3 charges
within ±10% of their median amount are a regular interval apart (weekly 6–8 days, monthly 28–33,
quarterly 85–97, yearly 355–375). Dismissed suggestions are never suggested again. Imports already
run this. Returns how many suggestions are open; review them with list_subscriptions.
TEXT)]
#[IsIdempotent]
#[IsDestructive(false)]
class DetectSubscriptionsTool extends FinanceTool
{
    public function __construct(private ManageSubscriptions $subscriptions) {}

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return Response::structured($this->subscriptions->detect($user));
    }
}
