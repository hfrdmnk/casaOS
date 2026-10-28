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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_import_batch')]
#[Description('Get one CSV import with its full summary (see list_import_batches), `blocked_by`, and how many of the transactions it created still exist. List those transactions with list_transactions if needed.')]
#[IsReadOnly]
class GetImportBatchTool extends FinanceTool
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

        return Response::structured([
            'batch' => [
                ...Present::batch($batch, $batch->undone_at === null ? $this->undo->blockedBy($batch) : null),
                'transactions_count' => $batch->transactions()->count(),
            ],
        ]);
    }
}
