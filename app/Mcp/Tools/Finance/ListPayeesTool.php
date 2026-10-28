<?php

namespace App\Mcp\Tools\Finance;

use App\Models\Finance\Payee;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_payees')]
#[Description('List the user\'s payees (the clean merchant or person names, e.g. "Migros" for "MIGROS M ZUERICH HB"), each with its id and transaction count. Use the ids in update_transaction, in `set_payee` rule actions, in `payee` rule conditions and in the `payee` filter of list_transactions.')]
#[IsReadOnly]
class ListPayeesTool extends FinanceTool
{
    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return Response::structured([
            'payees' => Payee::query()->ownedBy($user)->withCount('transactions')->orderBy('name')->get()
                ->map(fn (Payee $payee): array => Present::payee($payee))->all(),
        ]);
    }
}
