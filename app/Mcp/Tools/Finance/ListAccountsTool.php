<?php

namespace App\Mcp\Tools\Finance;

use App\Models\Finance\Account;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_accounts')]
#[Description(<<<'TEXT'
List the user's accounts (active first, then archived) with their balances. For each account:
`balance_today` is the balance at the end of today, computed from the nearest balance snapshot plus
or minus the completed transactions in between (pending transactions are not included);
`pending_total` is the sum of pending transactions; `latest_snapshot` is the newest recorded
balance with its reconciliation `difference` (recorded minus expected from the previous snapshot
and the transactions in between; 0 means they agree, null for the first snapshot);
`mismatched_snapshots` counts snapshots whose difference is not 0. Amounts are integers in Rappen
(1 CHF = 100 Rappen, negative = money out) with a formatted `*_chf` string next to each.
TEXT)]
#[IsReadOnly]
class ListAccountsTool extends FinanceTool
{
    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $accounts = Account::query()->ownedBy($user)->orderByRaw('archived_at is not null')->orderBy('name')->get();

        return Response::structured([
            'accounts' => $accounts->map(fn (Account $account): array => Present::account($account))->all(),
        ]);
    }
}
