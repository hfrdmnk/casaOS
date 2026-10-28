<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Dashboard\DashboardSummary;
use App\Actions\Finance\Money;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_dashboard_summary')]
#[Description(<<<'TEXT'
The numbers of the casaOS dashboard for one month. `net_worth.total` is the sum of today's balances
of all non-archived accounts (investment and crypto snapshots included); `stale_since` is the oldest
latest-snapshot date among accounts whose newest snapshot is more than 45 days old (null when none
is). `account_groups` lists the balances by account type with `mismatched_snapshots` (snapshots
disagreeing with the computed balance). `spending` is the month's completed spending by category
(outflows only, transfers excluded, positive Rappen, `Uncategorized` bucket). `subscriptions` has
the active subscriptions expected in the next 30 days (overdue included) and the monthly recurring
cost. `attention` counts uncategorized transactions (transfers excluded) and open transfer and
subscription suggestions. Amounts are Rappen with `*_chf` strings.
TEXT)]
#[IsReadOnly]
class GetDashboardSummaryTool extends FinanceTool
{
    public function __construct(private DashboardSummary $summary) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()->pattern('^\d{4}-\d{2}$')->description('The month for spending, Y-m, e.g. 2026-10. Defaults to this month.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return Response::structured(self::withChf(($this->summary)($user, $data['month'] ?? today()->format('Y-m'))));
    }

    /**
     * Add a `<key>_chf` string next to every amount, at any depth.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $data
     * @return array<TKey|string, mixed>
     */
    private static function withChf(array $data): array
    {
        $money = ['total', 'balance', 'amount', 'monthly_total', 'upcoming_total', 'latest_difference'];
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = is_array($value) ? self::withChf($value) : $value;

            if (in_array($key, $money, true) && (is_int($value) || $value === null)) {
                $result["{$key}_chf"] = $value === null ? null : Money::chf($value);
            }
        }

        return $result;
    }
}
