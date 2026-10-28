<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Import\CsvReader;
use App\Actions\Finance\Import\ImportFile;
use App\Actions\Finance\Import\ImportRejected;
use App\Actions\Finance\Import\ImportUpload;
use App\Actions\Finance\Import\ParsedRow;
use App\Actions\Finance\Import\UndoImportBatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ImportMappingRequest;
use App\Models\Finance\Account;
use App\Models\Finance\AccountType;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\TransactionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one Import page. An upload whose shape matches saved mappings imports straight away; any
 * other upload goes through the mapper wizard, which saves mappings for it and then imports.
 */
class ImportController extends Controller
{
    private const PREVIEW_ROWS = 15;

    public function index(Request $request, UndoImportBatch $undo): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('finance/import/index', [
            'mappings' => ImportMapping::query()->ownedBy($user)->with('account')->orderBy('name')->get()
                ->map(fn (ImportMapping $mapping): array => self::presentMapping($mapping))->all(),
            'batches' => ImportBatch::query()->ownedBy($user)->latest('id')->limit(20)->get()
                ->map(fn (ImportBatch $batch): array => ImportBatchController::present($batch, $undo))->all(),
        ]);
    }

    /**
     * Upload a file. With matching mappings it is imported now; otherwise the wizard opens.
     */
    public function store(Request $request, ImportFile $import): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:csv,txt'],
        ], [
            'file.extensions' => 'Upload a CSV file.',
        ]);

        /** @var User $user */
        $user = $request->user();
        $upload = ImportUpload::store($user, $request->file('file'));
        $mappings = $import->matchingMappings($user, $upload->bytes());

        if ($mappings->isEmpty()) {
            return to_route('finance.import.show', $upload->id);
        }

        return $this->importUpload($user, $upload, $mappings, $import);
    }

    /**
     * The mapper wizard for an upload.
     */
    public function show(Request $request, string $upload, ImportFile $import): Response
    {
        /** @var User $user */
        $user = $request->user();
        $file = ImportUpload::findOrFail($user, $upload);
        $bytes = $file->bytes();
        $detected = CsvReader::detect($bytes);
        $lines = array_slice(explode("\n", CsvReader::decode($bytes, $detected['encoding'])), 0, 12);

        return Inertia::render('finance/import/show', [
            'upload' => ['id' => $file->id, 'filename' => $file->filename],
            'detected' => $detected,
            'lines' => array_map(fn (string $line): string => mb_strimwidth(rtrim($line, "\r"), 0, 300, '…'), $lines),
            'matching' => $import->matchingMappings($user, $bytes)
                ->map(fn (ImportMapping $mapping): array => self::presentMapping($mapping))->all(),
            'accounts' => Account::query()->ownedBy($user)->active()
                ->whereIn('type', AccountType::importable())
                ->orderBy('name')->get()
                ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->value])->all(),
            'types' => array_values(array_filter(AccountType::options(), fn (array $type): bool => $type['importable'])),
            'rejection' => session('import_rejection'),
        ]);
    }

    /**
     * Read the upload with the wizard's settings. Without `parse` it returns the header, the
     * first rows and each column's distinct values; with `parse` it also reads every row through
     * the mapping and reports what an import would do.
     */
    public function preview(ImportMappingRequest $request, string $upload): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $bytes = ImportUpload::findOrFail($user, $upload)->bytes();
        $settings = $request->safe()->only(['encoding', 'delimiter', 'skip_lines']);
        $file = CsvReader::read($bytes, (string) $settings['encoding'], (string) $settings['delimiter'], (int) $settings['skip_lines']);

        $distinct = array_map(function (int $column) use ($file): array {
            $values = array_unique(array_map(fn (array $row): string => trim($row['cells'][$column] ?? ''), $file['rows']));

            return count($values) <= 12 ? array_values($values) : [];
        }, array_keys($file['header']));

        $response = [
            'header' => $file['header'],
            'rows' => array_map(fn (array $row): array => $row['cells'], array_slice($file['rows'], 0, 8)),
            'row_count' => count($file['rows']),
            'distinct' => $distinct,
            'parsed' => null,
        ];

        if ($request->boolean('parse')) {
            $mapping = new ImportMapping($request->mappingAttributes());
            $selected = array_values(array_filter($file['rows'], fn (array $row): bool => count($row['cells']) !== count($file['header']) || $mapping->selects($row['cells'])));
            $parsed = array_map(fn (array $row): ParsedRow => $mapping->parseRow($row['cells'], $row['line']), $selected);
            $failed = array_values(array_filter($parsed, fn (ParsedRow $row): bool => $row->errors !== []));
            $withBalance = array_values(array_filter($parsed, fn (ParsedRow $row): bool => $row->errors === [] && $row->balance !== null && $row->status !== TransactionStatus::Reverted));

            $response['parsed'] = [
                'selected' => count($selected),
                'failed' => count($failed),
                'foreign_currency' => count(array_filter($parsed, fn (ParsedRow $row): bool => $row->foreignCurrency)),
                'statuses' => array_count_values(array_map(fn (ParsedRow $row): string => $row->status->value, array_filter($parsed, fn (ParsedRow $row): bool => ! $row->foreignCurrency && $row->errors === []))),
                'last_balance' => $withBalance === [] ? null : end($withBalance)->balance,
                'errors' => array_map(fn (ParsedRow $row): array => ['line' => $row->line, 'messages' => $row->errors], array_slice($failed, 0, 20)),
                'rows' => array_map(fn (ParsedRow $row): array => $row->toPreview(), array_slice($parsed, 0, self::PREVIEW_ROWS)),
            ];
        }

        return response()->json($response);
    }

    /**
     * Import an upload through every saved mapping matching its shape.
     */
    public function commit(Request $request, string $upload, ImportFile $import): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = ImportUpload::findOrFail($user, $upload);
        $mappings = $import->matchingMappings($user, $file->bytes());

        if ($mappings->isEmpty()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Save a mapping for this file first.')]);

            return to_route('finance.import.show', $file->id);
        }

        return $this->importUpload($user, $file, $mappings, $import);
    }

    /**
     * @param  Collection<int, ImportMapping>  $mappings
     */
    private function importUpload(User $user, ImportUpload $upload, Collection $mappings, ImportFile $import): RedirectResponse
    {
        try {
            $batch = $import($user, $upload->bytes(), $upload->filename, $mappings);
        } catch (ImportRejected $rejected) {
            return to_route('finance.import.show', $upload->id)->with('import_rejection', [
                'message' => $rejected->getMessage(),
                'errors' => array_slice($rejected->errors, 0, 100),
                'error_count' => count($rejected->errors),
            ]);
        }

        $upload->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Imported :file.', ['file' => $upload->filename])]);

        return to_route('finance.batches.show', $batch);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentMapping(ImportMapping $mapping): array
    {
        return [
            'id' => $mapping->id,
            'name' => $mapping->name,
            'account_id' => $mapping->account_id,
            'account' => $mapping->account->name,
            'columns' => count($mapping->header),
            'header' => implode($mapping->delimiter === "\t" ? ' ⇥ ' : " {$mapping->delimiter} ", $mapping->header),
            'filter' => $mapping->filter_column === null ? null : [
                'column' => $mapping->header[$mapping->filter_column] ?? '',
                'value' => $mapping->filter_value,
            ],
        ];
    }
}
