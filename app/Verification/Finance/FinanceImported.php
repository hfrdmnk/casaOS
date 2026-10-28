<?php

namespace App\Verification\Finance;

use App\Actions\Finance\Import\ImportFile;
use App\Models\Finance\AccountType;
use App\Models\Finance\AmountMode;
use App\Models\Finance\ImportMapping;
use App\Models\User;
use App\Verification\Contracts\ScenarioState;

/**
 * One checking account with a saved mapping and one imported batch of synthetic rows: completed,
 * pending, a fee and a balance column, so balances, snapshots, undo and categorizing can be driven
 * without uploading a file.
 */
class FinanceImported implements ScenarioState
{
    public const CSV = <<<'CSV'
Date,Description,Amount,Fee,State,Balance
2026-10-01,Salary,3000.00,0.00,COMPLETED,3000.00
2026-10-02,Migros,-45.30,0.00,COMPLETED,2954.70
2026-10-03,Card payment abroad,-20.00,0.10,COMPLETED,2934.60
2026-10-04,Coffee,-4.50,0.00,PENDING,
CSV;

    public function __construct(private ImportFile $import) {}

    public function name(): string
    {
        return 'finance-imported';
    }

    public function description(): string
    {
        return 'A checking account "Verify Checking" with a saved mapping and one import of 4 synthetic rows (1 pending, 1 fee); balance 2934.60.';
    }

    public function arrange(User $user): array
    {
        $account = $user->accounts()->create(['name' => 'Verify Checking', 'type' => AccountType::Checking]);

        $mapping = new ImportMapping([
            'account_id' => $account->id,
            'name' => 'Verify export',
            'encoding' => 'UTF-8',
            'delimiter' => ',',
            'skip_lines' => 0,
            'header' => ['Date', 'Description', 'Amount', 'Fee', 'State', 'Balance'],
            'date_column' => 0,
            'date_format' => 'Y-m-d',
            'description_column' => 1,
            'amount_mode' => AmountMode::Signed,
            'amount_column' => 2,
            'decimal_separator' => '.',
            'fee_column' => 3,
            'status_column' => 4,
            'status_map' => ['COMPLETED' => 'completed', 'PENDING' => 'pending', 'REVERTED' => 'reverted'],
            'balance_column' => 5,
        ]);
        $mapping->user_id = $user->id;
        $mapping->save();

        $batch = ($this->import)($user, self::CSV."\n", 'verify-export.csv', $this->import->matchingMappings($user, self::CSV."\n"));

        return [
            'account_id' => $account->id,
            'mapping_id' => $mapping->id,
            'batch_id' => $batch->id,
            'transaction_ids' => $account->transactions()->orderBy('id')->pluck('id')->all(),
        ];
    }
}
