<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Rules\EditTransaction;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_transaction')]
#[Description(<<<'TEXT'
Set a transaction's category and/or payee by hand, like the user does in the web list. A value set
here is `manual`: rules never overwrite it. Passing null for a field clears it and hands it back
to the rules, which run again at once and may fill it (`rules_filled` lists what they set). Omit a
field to leave it unchanged; give at least one. Transfers take no category: unmark the transfer
first. Prefer creating a rule (create_rule + apply_rules) when many transactions share a pattern;
use this for one-off corrections.
TEXT)]
#[IsIdempotent]
class UpdateTransactionTool extends FinanceTool
{
    public function __construct(private EditTransaction $edit) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->description('The transaction id.')->required(),
            'category_id' => $schema->integer()->nullable()->description('A category id from list_categories, or null to hand the category back to the rules.'),
            'payee_id' => $schema->integer()->nullable()->description('A payee id from list_payees, or null to hand the payee back to the rules.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'payee_id' => ['nullable', 'integer'],
        ]);

        $fields = array_intersect_key($request->all(), array_flip(['category_id', 'payee_id']));

        if ($fields === []) {
            return Response::error('Give category_id, payee_id or both (null clears a field).');
        }

        $transaction = Transaction::query()->ownedBy($user)->findOrFail((int) $data['transaction_id']);
        /** @var array{category_id?: int|null, payee_id?: int|null} $fields */
        $filled = ($this->edit)($transaction, $fields);

        return Response::structured([
            'transaction' => Present::transaction($transaction->refresh()),
            'rules_filled' => Present::changes(Present::names($user), $filled),
        ]);
    }
}
