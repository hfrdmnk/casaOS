<?php

namespace App\Verification\Finance;

use App\Actions\Finance\Import\ImportFile;
use App\Actions\Finance\RecordBalanceSnapshot;
use App\Actions\Finance\Rules\ManageRules;
use App\Models\Finance\AccountType;
use App\Models\Finance\AmountMode;
use App\Models\Finance\ImportMapping;
use App\Models\User;
use App\Verification\Contracts\ScenarioState;

/**
 * A checking account and one import of synthetic charges dated relative to today, built to prove
 * subscription detection and to populate the dashboard: a monthly streaming charge whose
 * descriptions differ only in a reference number, a yearly domain renewal, a gym whose price
 * creeps up within ±10%, usage-based cloud storage whose amount drifts far more, an irregular
 * kiosk, and groceries a rule categorizes. Also an investment account whose only snapshot is 60
 * days old, so the net worth is flagged stale.
 */
class FinanceSubscriptions implements ScenarioState
{
    public function __construct(private ImportFile $import, private ManageRules $rules, private RecordBalanceSnapshot $snapshot) {}

    public function name(): string
    {
        return 'finance-subscriptions';
    }

    public function description(): string
    {
        return 'Accounts "Verify Checking" (one import of synthetic charges: monthly Streamflix, yearly Domain renewal, Gym with creeping price, drifting Cloud storage, irregular Kiosk, Migros groceries via a rule) and "Verify Broker" (investment, snapshot 60 days old). Detection on import suggests Streamflix, Domain renewal and Gym only.';
    }

    /**
     * The import rows, oldest first: [days ago, description, amount].
     *
     * @return list<array{int, string, string}>
     */
    public static function rows(): array
    {
        return [
            [738, 'DOMAIN RENEWAL VERIFY.CH', '-18.00'],
            [373, 'DOMAIN RENEWAL VERIFY.CH', '-18.00'],
            [110, 'Salary', '4200.00'],
            [95, 'STREAMFLIX *4821', '-15.90'],
            [90, 'Cloud storage usage', '-3.10'],
            [85, 'Kiosk Bahnhof', '-9.50'],
            [80, 'Gym Fit Verify', '-49.00'],
            [71, 'Kiosk Bahnhof', '-9.50'],
            [64, 'STREAMFLIX *5530', '-15.90'],
            [60, 'Cloud storage usage', '-5.40'],
            [50, 'Gym Fit Verify', '-50.50'],
            [40, 'Kiosk Bahnhof', '-9.50'],
            [33, 'STREAMFLIX *6102', '-15.90'],
            [30, 'Cloud storage usage', '-8.90'],
            [20, 'Gym Fit Verify', '-52.00'],
            [12, 'Migros Bahnhof', '-34.60'],
            [11, 'Kiosk Bahnhof', '-9.50'],
            [8, 'DOMAIN RENEWAL VERIFY.CH', '-19.00'],
            [5, 'Migros City', '-21.45'],
            [3, 'STREAMFLIX *7730', '-15.90'],
            [2, 'Kiosk Bahnhof', '-9.50'],
            [1, 'Cloud storage usage', '-12.75'],
        ];
    }

    public function arrange(User $user): array
    {
        $account = $user->accounts()->create(['name' => 'Verify Checking', 'type' => AccountType::Checking]);
        $broker = $user->accounts()->create(['name' => 'Verify Broker', 'type' => AccountType::Investment]);
        ($this->snapshot)($broker, today()->subDays(60)->toDateString(), 500000);

        $this->rules->create($user, [
            'name' => 'Migros',
            'conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => 'migros']],
            'actions' => [['type' => 'set_category', 'value' => $user->categories()->where('name', 'Groceries')->value('id')]],
        ]);

        $mapping = new ImportMapping([
            'account_id' => $account->id,
            'name' => 'Verify subscriptions export',
            'encoding' => 'UTF-8',
            'delimiter' => ',',
            'skip_lines' => 0,
            'header' => ['Date', 'Description', 'Amount', 'State'],
            'date_column' => 0,
            'date_format' => 'Y-m-d',
            'description_column' => 1,
            'amount_mode' => AmountMode::Signed,
            'amount_column' => 2,
            'decimal_separator' => '.',
            'status_column' => 3,
            'status_map' => ['COMPLETED' => 'completed', 'PENDING' => 'pending', 'REVERTED' => 'reverted'],
        ]);
        $mapping->user_id = $user->id;
        $mapping->save();

        $bytes = self::csv();
        $batch = ($this->import)($user, $bytes, 'verify-subscriptions.csv', $this->import->matchingMappings($user, $bytes));
        // Today's balance as the bank shows it: CHF 1000 before the first row, plus every row.
        ($this->snapshot)($account, today()->toDateString(), 100000 + array_sum(array_map(fn (array $row): int => (int) round((float) $row[2] * 100), self::rows())));

        return [
            'account_id' => $account->id,
            'broker_account_id' => $broker->id,
            'batch_id' => $batch->id,
            'subscription_suggestions' => $batch->summary['subscription_suggestions'] ?? 0,
        ];
    }

    public static function csv(): string
    {
        $lines = ['Date,Description,Amount,State'];

        foreach (self::rows() as [$daysAgo, $description, $amount]) {
            $lines[] = today()->subDays($daysAgo)->toDateString().",{$description},{$amount},COMPLETED";
        }

        return implode("\n", $lines)."\n";
    }
}
