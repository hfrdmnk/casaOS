<?php

namespace App\Verification\Finance;

use App\Actions\Finance\Import\ImportFile;
use App\Models\Finance\AccountType;
use App\Models\Finance\AmountMode;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Transaction;
use App\Models\User;
use App\Verification\Contracts\ScenarioState;

/**
 * Two accounts fed from one Revolut-shaped file (a mapping per product) and one import of
 * synthetic rows: three pairs that pair on import (two share an amount on the same day), a
 * three-way tie that becomes two suggestions, a top-up from an untracked bank and a pending row.
 */
class FinanceTransfers implements ScenarioState
{
    public const CSV = <<<'CSV'
Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance
Transfer,Savings,2026-10-01 09:00:00,2026-10-01 09:00:00,To pocket Verify from CHF,0.50,0.00,CHF,COMPLETED,100.50
Transfer,Current,2026-10-01 09:00:00,2026-10-01 09:00:00,To pocket Verify from CHF,-0.50,0.00,CHF,COMPLETED,999.50
Transfer,Savings,2026-10-01 15:30:00,2026-10-01 15:30:00,To pocket Verify from CHF,0.50,0.00,CHF,COMPLETED,101.00
Transfer,Current,2026-10-01 15:30:00,2026-10-01 15:30:00,To pocket Verify from CHF,-0.50,0.00,CHF,COMPLETED,999.00
Transfer,Savings,2026-10-02 18:00:00,2026-10-02 18:00:00,Pocket Withdrawal,-20.00,0.00,CHF,COMPLETED,81.00
Transfer,Current,2026-10-02 18:00:00,2026-10-02 18:00:00,Pocket Withdrawal,20.00,0.00,CHF,COMPLETED,1019.00
Deposit,Current,2026-10-03 06:00:00,2026-10-03 06:00:00,Payment from Verify Person,300.00,0.00,CHF,COMPLETED,1319.00
Transfer,Savings,2026-10-04 10:00:00,2026-10-04 10:00:00,Round-up,5.00,0.00,CHF,COMPLETED,86.00
Transfer,Savings,2026-10-04 10:00:00,2026-10-04 10:00:00,Round-up,5.00,0.00,CHF,COMPLETED,91.00
Transfer,Current,2026-10-04 10:00:00,2026-10-04 10:00:00,Round-up,-5.00,0.00,CHF,COMPLETED,1314.00
Card Payment,Current,2026-10-05 12:00:00,,Kiosk,-4.00,0.00,CHF,PENDING,
CSV;

    public function __construct(private ImportFile $import) {}

    public function name(): string
    {
        return 'finance-transfers';
    }

    public function description(): string
    {
        return 'Accounts "Verify Current" and "Verify Savings" with a mapping each and one import of 11 synthetic rows: 3 pairs paired on import, a three-way tie as 2 suggestions, an unpaired "Payment from Verify Person" and a pending row.';
    }

    public function arrange(User $user): array
    {
        $current = $user->accounts()->create(['name' => 'Verify Current', 'type' => AccountType::Checking]);
        $savings = $user->accounts()->create(['name' => 'Verify Savings', 'type' => AccountType::Savings]);

        foreach (['Current' => $current, 'Savings' => $savings] as $product => $account) {
            $mapping = new ImportMapping([
                'account_id' => $account->id,
                'name' => "Verify {$product}",
                'encoding' => 'UTF-8',
                'delimiter' => ',',
                'skip_lines' => 0,
                'header' => ['Type', 'Product', 'Started Date', 'Completed Date', 'Description', 'Amount', 'Fee', 'Currency', 'State', 'Balance'],
                'date_column' => 2,
                'date_format' => 'Y-m-d H:i:s',
                'description_column' => 4,
                'amount_mode' => AmountMode::Signed,
                'amount_column' => 5,
                'decimal_separator' => '.',
                'fee_column' => 6,
                'currency_column' => 7,
                'status_column' => 8,
                'status_map' => ['COMPLETED' => 'completed', 'PENDING' => 'pending', 'REVERTED' => 'reverted'],
                'balance_column' => 9,
                'filter_column' => 1,
                'filter_value' => $product,
            ]);
            $mapping->user_id = $user->id;
            $mapping->save();
        }

        $bytes = self::CSV."\n";
        $batch = ($this->import)($user, $bytes, 'verify-transfers.csv', $this->import->matchingMappings($user, $bytes));

        return [
            'current_account_id' => $current->id,
            'savings_account_id' => $savings->id,
            'batch_id' => $batch->id,
            'paired_as_transfers' => $batch->summary['paired_as_transfers'] ?? 0,
            'transfer_suggestions' => $batch->summary['transfer_suggestions'] ?? 0,
            'payment_from_id' => Transaction::query()->ownedBy($user)->where('description', 'Payment from Verify Person')->value('id'),
            'paired_ids' => Transaction::query()->ownedBy($user)->whereNotNull('transfer_counterpart_id')->orderBy('id')->pluck('id')->all(),
        ];
    }
}
