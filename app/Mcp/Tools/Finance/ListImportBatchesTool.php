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

#[Name('list_import_batches')]
#[Description('List the user\'s CSV imports, newest first. Each batch has its filename, when it ran, `undone_at` when it was undone, its `summary` (rows created, updated, skipped as duplicates or foreign currency, removed as reverted, categorized by rules, paired as transfers, per account with the balance snapshot it recorded) and `blocked_by`: a later import into the same account that must be undone first.')]
#[IsReadOnly]
class ListImportBatchesTool extends FinanceTool
{
    public function __construct(private UndoImportBatch $undo) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(100)->default(20)->description('At most this many batches, up to 100.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return Response::structured([
            'batches' => ImportBatch::query()->ownedBy($user)->latest('id')->limit((int) ($data['limit'] ?? 20))->get()
                ->map(fn (ImportBatch $batch): array => Present::batch($batch, $batch->undone_at === null ? $this->undo->blockedBy($batch) : null))->all(),
        ]);
    }
}
