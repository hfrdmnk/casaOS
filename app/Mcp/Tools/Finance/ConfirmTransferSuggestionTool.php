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

#[Name('confirm_transfer_suggestion')]
#[Description('Confirm a transfer suggestion: its outflow and inflow become a transfer pair, as if paired by hand (source manual, categories cleared). Fails when either side has been paired since.')]
class ConfirmTransferSuggestionTool extends FinanceTool
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
        $this->transfers->confirm($suggestion);

        return Response::structured([
            'message' => 'Paired as a transfer.',
            'outflow' => Present::transaction($suggestion->outflow->refresh()),
            'inflow' => Present::transaction($suggestion->inflow->refresh()),
        ]);
    }
}
