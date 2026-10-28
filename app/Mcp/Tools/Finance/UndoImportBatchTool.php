<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Import\UndoImportBatch;
use App\Models\Finance\ImportBatch;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('undo_import_batch')]
#[Description('Undo everything one CSV import did: transactions and balance snapshots it created are deleted, transactions it updated get their old values back, rows it removed as reverted come back, and transfer pairs it made are broken. Categories set by hand since then on updated rows stay. Imports are undone newest first per account: a batch with `blocked_by` needs that later import undone first. Cannot be redone except by importing the file again.')]
#[IsDestructive]
class UndoImportBatchTool extends FinanceTool
{
    public function __construct(private UndoImportBatch $undo) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'batch_id' => $schema->integer()->description('The import batch id.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['batch_id' => ['required', 'integer']]);
        $batch = ImportBatch::query()->ownedBy($user)->findOrFail((int) $data['batch_id']);

        if ($batch->undone_at !== null) {
            return Response::error("Import batch {$batch->id} ({$batch->filename}) was already undone.");
        }

        $blocker = $this->undo->blockedBy($batch);

        if ($blocker !== null) {
            return Response::error("Undo the later import batch {$blocker->id} ({$blocker->filename}) first: it imported into the same account.");
        }

        ($this->undo)($batch);

        return Response::structured(['batch' => Present::batch($batch->refresh())]);
    }
}
