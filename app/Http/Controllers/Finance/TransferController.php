<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Transfers\ManageTransfers;
use App\Http\Controllers\Controller;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Transfer suggestions and the manual transfer actions on a transaction. The logic lives in
 * ManageTransfers.
 */
class TransferController extends Controller
{
    /**
     * Suggested pairs waiting for the user to confirm or dismiss them.
     */
    public function index(Request $request, ManageTransfers $transfers): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('finance/transfers/index', [
            'suggestions' => $transfers->suggestions($user)->map(fn (TransferSuggestion $suggestion): array => [
                'id' => $suggestion->id,
                'outflow' => TransactionController::present($suggestion->outflow),
                'inflow' => TransactionController::present($suggestion->inflow),
            ])->all(),
            'paired' => intdiv(Transaction::query()->ownedBy($user)->whereNotNull('transfer_counterpart_id')->count(), 2),
        ]);
    }

    /**
     * "Find transfers" across all the user's transactions.
     */
    public function find(Request $request, ManageTransfers $transfers): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $transfers->find($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            'Paired :count transfer. :suggested suggested pairs to review.|Paired :count transfers. :suggested suggested pairs to review.',
            $result['paired'],
            ['suggested' => $result['suggested']],
        )]);

        return back();
    }

    public function confirm(TransferSuggestion $suggestion, ManageTransfers $transfers): RedirectResponse
    {
        $transfers->confirm($suggestion);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Paired as a transfer.')]);

        return back();
    }

    public function dismiss(TransferSuggestion $suggestion, ManageTransfers $transfers): RedirectResponse
    {
        $transfers->dismiss($suggestion);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Suggestion dismissed.')]);

        return back();
    }

    /**
     * Transactions this one can be paired with: the pairing candidates, or a search.
     */
    public function candidates(Request $request, Transaction $transaction, ManageTransfers $transfers): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:200']]);

        return response()->json([
            'candidates' => $transfers->candidates($transaction, $request->string('search')->toString())
                ->map(fn (Transaction $candidate): array => TransactionController::present($candidate))->all(),
        ]);
    }

    public function pair(Request $request, Transaction $transaction, ManageTransfers $transfers): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['counterpart_id' => ['required', 'integer']]);
        $counterpart = Transaction::query()->ownedBy($user)->findOrFail($request->integer('counterpart_id'));

        $transfers->pair($transaction, $counterpart);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Paired as a transfer.')]);

        return back();
    }

    public function unpair(Transaction $transaction, ManageTransfers $transfers): RedirectResponse
    {
        $transfers->unpair($transaction);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unpaired. Both sides stay transfers without a counterpart.')]);

        return back();
    }

    public function mark(Transaction $transaction, ManageTransfers $transfers): RedirectResponse
    {
        $transfers->markWithoutCounterpart($transaction);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Marked as a transfer.')]);

        return back();
    }

    public function unmark(Transaction $transaction, ManageTransfers $transfers): RedirectResponse
    {
        $transfers->unmark($transaction);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('No longer a transfer.')]);

        return back();
    }
}
