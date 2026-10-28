<?php

namespace App\Actions\Finance;

use App\Models\Finance\Account;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\SnapshotSource;

/**
 * Records an account's balance at the end of a day by hand. A day has one snapshot; recording it
 * again replaces it (also one an import created).
 */
final class RecordBalanceSnapshot
{
    /**
     * @param  string  $date  Y-m-d
     * @param  int  $balance  signed Rappen
     */
    public function __invoke(Account $account, string $date, int $balance): BalanceSnapshot
    {
        $snapshot = BalanceSnapshot::query()->firstOrNew(['account_id' => $account->id, 'date' => $date]);
        $snapshot->user_id = $account->user_id;
        $snapshot->fill([
            'balance' => $balance,
            'source' => SnapshotSource::Manual,
            'import_batch_id' => null,
        ])->save();

        return $snapshot;
    }
}
