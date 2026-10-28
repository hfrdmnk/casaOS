<?php

namespace App\Mcp\Tools\Finance;

use App\Actions\Finance\Transactions\FilterTransactions;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_transactions')]
#[Description(<<<'TEXT'
List the user's transactions, newest first, with the same filters as the web transaction list.
All filters are optional and combine with AND. Use `category: "none"` to find uncategorized
transactions (the starting point for categorizing). Each transaction has `amount` in signed Rappen
(net of fees; negative = money out), `status` (`completed` or `pending`), its category and payee
with who set them (`category_source`/`payee_source`: `manual` = set by hand and never changed by
rules, `rule` = set by a rule, null = empty), `is_transfer` (transfers have no category),
`counterpart` (the other side of a transfer pair) and `suggested_transfer` (part of an open
transfer suggestion). Paginated: read `last_page` and ask for the next `page`.
TEXT)]
#[IsReadOnly]
class ListTransactionsTool extends FinanceTool
{
    public function __construct(private FilterTransactions $filter) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->integer()->description('Only this account id.'),
            'category' => $schema->string()->pattern('^(none|\d+)$')->description('A category id (as a string, e.g. "12"), or "none" for uncategorized transactions.'),
            'payee' => $schema->string()->pattern('^(none|\d+)$')->description('A payee id (as a string), or "none" for transactions without a payee.'),
            'from' => $schema->string()->format('date')->description('Earliest date, Y-m-d, inclusive.'),
            'to' => $schema->string()->format('date')->description('Latest date, Y-m-d, inclusive.'),
            'search' => $schema->string()->max(200)->description('Case-insensitive text in the raw bank description or the payee name.'),
            'transfer' => $schema->string()->enum(['yes', 'no', 'suggested'])->description('`yes`: only transfers; `no`: no transfers; `suggested`: rows in an open transfer suggestion.'),
            'subscription' => $schema->string()->pattern('^(any|none|\d+)$')->description('A subscription id (as a string), "any" for charges linked to a subscription, or "none".'),
            'page' => $schema->integer()->min(1)->default(1)->description('Page number, from 1.'),
            'per_page' => $schema->integer()->min(1)->max(200)->default(50)->description('Rows per page, at most 200.'),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $input = $request->all();

        foreach (['category', 'payee', 'subscription'] as $key) {
            if (is_int($input[$key] ?? null)) {
                $input[$key] = (string) $input[$key];
            }
        }

        $request->merge($input);
        $data = $request->validate([
            ...FilterTransactions::rules(),
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = $this->filter->query($user, $data)->paginate((int) ($data['per_page'] ?? 50), page: (int) ($data['page'] ?? 1));
        $inSuggestion = $this->filter->inSuggestion($user, $page->getCollection()->modelKeys());

        return Response::structured([
            'transactions' => $page->getCollection()->map(fn (Transaction $transaction): array => [
                ...Present::transaction($transaction),
                'suggested_transfer' => isset($inSuggestion[$transaction->id]),
            ])->all(),
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }
}
