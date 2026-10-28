<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\BalanceSnapshotRequest;
use App\Models\Finance\Account;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\SnapshotSource;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class BalanceSnapshotController extends Controller
{
    /**
     * Record the account's balance at the end of a day. A day has one snapshot; entering it again
     * replaces it.
     */
    public function store(BalanceSnapshotRequest $request, Account $account): RedirectResponse
    {
        $snapshot = BalanceSnapshot::query()->firstOrNew(['account_id' => $account->id, 'date' => $request->validated('date')]);
        $snapshot->user_id = $account->user_id;
        $snapshot->fill([
            'balance' => (int) Money::parse((string) $request->validated('balance'), '.', "'"),
            'source' => SnapshotSource::Manual,
            'import_batch_id' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Balance saved.')]);

        return to_route('finance.accounts.show', $account);
    }

    public function destroy(BalanceSnapshot $snapshot): RedirectResponse
    {
        $snapshot->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Balance deleted.')]);

        return to_route('finance.accounts.show', $snapshot->account_id);
    }
}
