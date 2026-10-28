<?php

namespace App\Mcp\Tools\Finance;

use App\Models\Finance\Account;
use App\Models\Finance\ImportMapping;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_account')]
#[Description(<<<'TEXT'
Get one account with the same balance fields as list_accounts, plus every balance snapshot (newest
first) with its reconciliation: `expected` is the previous snapshot plus the completed transactions
in between, `difference` is recorded minus expected (null for the oldest). `source` is `manual` or
`import`. Also lists the names of the saved CSV import mappings that feed this account. Amounts are
integers in Rappen with `*_chf` strings.
TEXT)]
#[IsReadOnly]
class GetAccountTool extends FinanceTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->description('The account id (from list_accounts).')->required(),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate(['account_id' => ['required', 'integer']]);
        $account = Account::query()->ownedBy($user)->findOrFail((int) $data['account_id']);

        return Response::structured([
            'account' => Present::account($account),
            'snapshots' => Present::snapshots($account),
            'import_mappings' => $account->mappings()->orderBy('name')->get()
                ->map(fn (ImportMapping $mapping): array => ['id' => $mapping->id, 'name' => $mapping->name])->all(),
        ]);
    }
}
