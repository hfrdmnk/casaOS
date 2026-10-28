<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Import\ImportFile;
use App\Actions\Finance\Import\ImportRejected;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('import_csv')]
#[Description(<<<'TEXT'
Import a bank CSV export, given as the file's full text content. It is read through every saved
import mapping whose shape (encoding, delimiter, header row) matches the file, exactly like an
upload in the web UI: re-imported rows are skipped as duplicates, pending rows that completed are
updated, rules run on new rows and transfers are paired. Nothing is written when any row fails to
parse. There is no mapping wizard here: when no saved mapping matches, the tool fails and the user
must upload the file once at Finance → Import in the web UI to set up a mapping. Returns the import
batch and its summary; undo it with undo_import_batch.
TEXT)]
#[IsDestructive(false)]
class ImportCsvTool extends FinanceTool
{
    public function __construct(private ImportFile $import) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->description('The CSV file content, exactly as exported by the bank (UTF-8 text, header row included).')->required(),
            'filename' => $schema->string()->max(200)->default('mcp-import.csv')->description('A filename to show in the import history, e.g. "revolut-2026-10.csv".'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:10485760'],
            'filename' => ['nullable', 'string', 'max:200'],
        ], ['content.max' => 'The file is larger than 10 MB, the import limit.']);

        $mappings = $this->import->matchingMappings($user, $data['content']);

        if ($mappings->isEmpty()) {
            return Response::error('No saved import mapping matches this file\'s columns, so nothing was imported. Ask the user to upload this file once in the casaOS web UI at Finance → Import and save a mapping for it; after that, import_csv works for files of the same shape.');
        }

        try {
            $batch = ($this->import)($user, $data['content'], basename($data['filename'] ?? 'mcp-import.csv'), $mappings);
        } catch (ImportRejected $rejected) {
            return Response::error($rejected->getMessage().' '.collect($rejected->errors)->take(20)
                ->map(fn (array $error): string => "Line {$error['line']}: {$error['message']}.")
                ->implode(' '));
        }

        return Response::structured(['batch' => Present::batch($batch)]);
    }
}
