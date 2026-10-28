<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Transfers\ManageTransfers;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('dismiss_transfer_suggestion')]
#[Description('Dismiss a transfer suggestion for good: these two transactions are never suggested or paired automatically again. Neither transaction changes.')]
class DismissTransferSuggestionTool extends FinanceTool
{
    public function __construct(private ManageTransfers $transfers) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'suggestion_id' => $schema->integer()->description('The suggestion id (from list_transfer_suggestions).')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['suggestion_id' => ['required', 'integer']]);
        $suggestion = TransferSuggestion::query()->ownedBy($user)->whereNull('dismissed_at')->findOrFail((int) $data['suggestion_id']);
        $this->transfers->dismiss($suggestion);

        return Response::structured([
            'message' => 'Suggestion dismissed.',
            'outflow' => Present::transaction($suggestion->outflow->refresh()),
            'inflow' => Present::transaction($suggestion->inflow->refresh()),
        ]);
    }
}
