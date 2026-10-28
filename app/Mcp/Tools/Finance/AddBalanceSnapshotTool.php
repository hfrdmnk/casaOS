<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\RecordBalanceSnapshot;
use App\Models\Finance\Account;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('add_balance_snapshot')]
#[Description(<<<'TEXT'
Record an account's closing balance at the end of a day, as the bank shows it. A day has one
snapshot: recording the same date again replaces it (also one an import created). Balances are
computed from the nearest snapshot, so a snapshot also corrects `balance_today`. Returns the
account with its updated balance and snapshots.
TEXT)]
#[IsIdempotent]
class AddBalanceSnapshotTool extends FinanceTool
{
    public function __construct(private RecordBalanceSnapshot $record) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->description('The account id (from list_accounts).')->required(),
            'date' => $schema->string()->format('date')->description('The day the balance closes, Y-m-d, e.g. 2026-10-27.')->required(),
            'balance' => $schema->integer()->description('The closing balance in signed Rappen: CHF 1234.50 is 123450, an overdraft of CHF 80 is -8000.')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'balance' => ['required', 'integer'],
        ], ['balance.integer' => 'Give the balance as an integer in Rappen, e.g. 123450 for CHF 1234.50.']);

        $account = Account::query()->ownedBy($user)->findOrFail((int) $data['account_id']);
        $snapshot = ($this->record)($account, $data['date'], (int) $data['balance']);

        return Response::structured([
            'snapshot_id' => $snapshot->id,
            'account' => Present::account($account),
            'snapshots' => Present::snapshots($account),
        ]);
    }
}
