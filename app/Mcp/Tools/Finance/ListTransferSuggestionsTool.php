<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Transfers\ManageTransfers;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_transfer_suggestions')]
#[Description('List open transfer suggestions, oldest first: pairs of an outflow and an inflow in different accounts that look like one transfer but were ambiguous (e.g. two candidates with the same amount). Show them to the user, then confirm_transfer_suggestion or dismiss_transfer_suggestion each one by `id`.')]
#[IsReadOnly]
class ListTransferSuggestionsTool extends FinanceTool
{
    public function __construct(private ManageTransfers $transfers) {}

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return Response::structured([
            'suggestions' => $this->transfers->suggestions($user)
                ->map(fn (TransferSuggestion $suggestion): array => Present::suggestion($suggestion))->all(),
        ]);
    }
}
