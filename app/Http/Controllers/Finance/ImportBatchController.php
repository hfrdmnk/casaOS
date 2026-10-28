<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Import\UndoImportBatch;
use App\Http\Controllers\Controller;
use App\Models\Finance\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ImportBatchController extends Controller
{
    public function show(ImportBatch $batch, UndoImportBatch $undo): Response
    {
        return Inertia::render('finance/batches/show', [
            'batch' => self::present($batch, $undo),
        ]);
    }

    /**
     * Undo everything a batch imported.
     */
    public function undo(ImportBatch $batch, UndoImportBatch $undo): RedirectResponse
    {
        if ($batch->undone_at !== null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('This import was already undone.')]);

            return to_route('finance.batches.show', $batch);
        }

        $blocker = $undo->blockedBy($batch);

        if ($blocker !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Undo the later import of :file first.', ['file' => $blocker->filename])]);

            return to_route('finance.batches.show', $batch);
        }

        $undo($batch);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Import undone.')]);

        return to_route('finance.batches.show', $batch);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ImportBatch $batch, UndoImportBatch $undo): array
    {
        $blocker = $batch->undone_at === null ? $undo->blockedBy($batch) : null;

        return [
            'id' => $batch->id,
            'filename' => $batch->filename,
            'created_at' => $batch->created_at?->toIso8601String(),
            'undone_at' => $batch->undone_at?->toIso8601String(),
            'summary' => $batch->summary,
            'blocked_by' => $blocker === null ? null : ['id' => $blocker->id, 'filename' => $blocker->filename],
        ];
    }
}
