<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Transfers\ManageTransfers;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('find_transfers')]
#[Description(<<<'TEXT'
"Find transfers" across all the user's transactions: pairs money leaving one account with the same
amount arriving in another (completed, same currency, opposite amounts to the Rappen, at most 3
days apart, neither decided by hand). Only unambiguous pairs are paired; the remaining candidates
become suggestions to review with list_transfer_suggestions. Imports already do this for new rows,
so run it after changing transactions by hand. Returns how many pairs were made and suggested.
TEXT)]
#[IsIdempotent]
#[IsDestructive(false)]
class FindTransfersTool extends FinanceTool
{
    public function __construct(private ManageTransfers $transfers) {}

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        return Response::structured($this->transfers->find($user));
    }
}
