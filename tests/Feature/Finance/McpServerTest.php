<?php

use App\Mcp\Prompts\Finance\CategorizeTransactionsPrompt;
use App\Mcp\Servers\FinanceServer;
use App\Mcp\Tools\Finance;
use App\Mcp\Tools\Finance\FinanceTool;
use App\Models\Finance\Account;
use App\Models\Finance\BalanceSnapshot;
use App\Models\Finance\Category;
use App\Models\Finance\FieldSource;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Payee;
use App\Models\Finance\Rule;
use App\Models\Finance\RuleActionType;
use App\Models\Finance\RuleConditionField;
use App\Models\Finance\Transaction;
use App\Models\Finance\TransactionStatus;
use App\Models\Finance\TransferSource;
use App\Models\Finance\TransferSuggestion;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\PendingTestResponse;

/**
 * The finance MCP server as the token's user, with the token's abilities.
 *
 * @param  list<string>  $abilities
 */
function financeMcp(User $user, array $abilities = [FinanceTool::READ, FinanceTool::WRITE]): PendingTestResponse
{
    $user->withAccessToken($user->createToken('test', $abilities)->accessToken);

    return FinanceServer::actingAs($user);
}

/**
 * Every tool class registered on the finance server.
 *
 * @return list<class-string<FinanceTool>>
 */
function financeTools(): array
{
    return (new ReflectionProperty(FinanceServer::class, 'tools'))->getDefaultValue();
}

/**
 * @return array<string, mixed>
 */
function mcpInitialize(): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-06-18',
        'capabilities' => [],
        'clientInfo' => ['name' => 'test', 'version' => '1'],
    ]];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['name' => 'Current']);
});

test('the endpoint answers a valid bearer token and refuses a missing, malformed or revoked one with 401', function () {
    $token = $this->user->createToken('agent', [FinanceTool::READ]);
    $bearer = fn (string $value): array => ['Authorization' => "Bearer {$value}"];

    $this->postJson('/mcp/finance', mcpInitialize())
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
    $this->postJson('/mcp/finance', mcpInitialize(), $bearer($token->plainTextToken.'x'))->assertUnauthorized();

    $this->postJson('/mcp/finance', mcpInitialize(), $bearer($token->plainTextToken))
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'casaOS Finance');

    $token->accessToken->delete();
    $this->app['auth']->forgetGuards();
    $this->postJson('/mcp/finance', mcpInitialize(), $bearer($token->plainTextToken))->assertUnauthorized();
});

test('a browser session does not authenticate the endpoint', function () {
    $this->actingAs($this->user)->postJson('/mcp/finance', mcpInitialize())->assertUnauthorized();
});

test('exactly the reading tools are read-only', function () {
    $readOnly = collect(financeTools())->filter(fn (string $tool): bool => app($tool)->isReadOnly())->map(fn (string $tool): string => app($tool)->name());

    expect($readOnly->sort()->values()->all())->toBe([
        'get_account', 'get_import_batch', 'get_transaction', 'list_accounts', 'list_categories', 'list_import_batches', 'list_payees',
        'list_rules', 'list_transactions', 'list_transfer_suggestions', 'preview_apply_rules', 'preview_rule_matches', 'transfer_candidates',
    ])->and(count(financeTools()))->toBe(37);
});

test('a read-only token is refused on every write tool and changes nothing', function (string $tool) {
    $category = Category::query()->ownedBy($this->user)->firstOrFail();

    financeMcp($this->user, [FinanceTool::READ])
        ->tool($tool, ['name' => 'Pets', 'category_id' => $category->id, 'account_id' => $this->account->id, 'date' => '2026-10-01', 'balance' => 100])
        ->assertHasErrors(['needs the finance:write ability, but this API token is read-only']);

    expect(Category::query()->ownedBy($this->user)->where('name', 'Pets')->exists())->toBeFalse()
        ->and($category->fresh())->not->toBeNull()
        ->and(BalanceSnapshot::query()->count())->toBe(0);
})->with(fn (): array => array_values(array_filter(financeTools(), fn (string $tool): bool => ! app($tool)->isReadOnly())));

test('a read-only token can use every read tool', function (string $tool) {
    $transaction = Transaction::factory()->for($this->account)->create();

    financeMcp($this->user, [FinanceTool::READ])
        ->tool($tool, [
            'account_id' => $this->account->id,
            'transaction_id' => $transaction->id,
            'conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => 'x']],
            'batch_id' => ImportBatch::factory()->for($this->user)->create()->id,
        ])
        ->assertHasNoErrors();
})->with(fn (): array => array_values(array_filter(financeTools(), fn (string $tool): bool => app($tool)->isReadOnly())));

test('a token without the read ability is refused on read tools', function () {
    financeMcp($this->user, [])->tool(Finance\ListAccountsTool::class)
        ->assertHasErrors(['needs the finance:read ability']);
});

test('another user\'s ids are not found and nothing of theirs changes', function (string $tool, string $model, array $arguments) {
    $owner = User::factory()->create();
    $account = Account::factory()->for($owner)->create();
    $rows = [
        'account' => $account,
        'transaction' => Transaction::factory()->for($account)->create(['description' => 'Owner coffee']),
        'category' => Category::query()->ownedBy($owner)->firstOrFail(),
        'payee' => Payee::factory()->for($owner)->create(),
        'rule' => Rule::factory()->for($owner)->create(),
        'batch' => ImportBatch::factory()->for($owner)->create(),
        'suggestion' => TransferSuggestion::query()->forceCreate([
            'user_id' => $owner->id,
            'outflow_id' => Transaction::factory()->for($account)->create(['amount' => -500])->id,
            'inflow_id' => Transaction::factory()->for(Account::factory()->for($owner))->create(['amount' => 500])->id,
        ]),
    ];
    $before = $rows[$model]->fresh()->toArray();
    $own = Transaction::factory()->for($this->account)->create(['amount' => 500]);

    $arguments = array_map(fn (mixed $value): mixed => match ($value) {
        'own' => $own->id,
        'id' => $rows[$model]->getKey(),
        default => $value,
    }, $arguments);
    $label = ['suggestion' => 'transfer suggestion', 'batch' => 'import batch'][$model] ?? $model;

    financeMcp($this->user)->tool($tool, $arguments)
        ->assertHasErrors(["Not found: no {$label} with id {$rows[$model]->getKey()}."]);

    expect($rows[$model]->fresh()?->toArray())->toBe($before);
})->with([
    'get_account' => [Finance\GetAccountTool::class, 'account', ['account_id' => 'id']],
    'add_balance_snapshot' => [Finance\AddBalanceSnapshotTool::class, 'account', ['account_id' => 'id', 'date' => '2026-10-01', 'balance' => '100']],
    'get_transaction' => [Finance\GetTransactionTool::class, 'transaction', ['transaction_id' => 'id']],
    'update_transaction' => [Finance\UpdateTransactionTool::class, 'transaction', ['transaction_id' => 'id', 'category_id' => null]],
    'update_category' => [Finance\UpdateCategoryTool::class, 'category', ['category_id' => 'id', 'name' => 'Mine']],
    'delete_category' => [Finance\DeleteCategoryTool::class, 'category', ['category_id' => 'id']],
    'rename_payee' => [Finance\RenamePayeeTool::class, 'payee', ['payee_id' => 'id', 'name' => 'Mine']],
    'merge_payees' => [Finance\MergePayeesTool::class, 'payee', ['payee_id' => 'id', 'into_payee_id' => 'id']],
    'delete_payee' => [Finance\DeletePayeeTool::class, 'payee', ['payee_id' => 'id']],
    'update_rule' => [Finance\UpdateRuleTool::class, 'rule', ['rule_id' => 'id', 'conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => 'x']], 'actions' => [['type' => 'mark_as_transfer']]]],
    'delete_rule' => [Finance\DeleteRuleTool::class, 'rule', ['rule_id' => 'id']],
    'set_rule_enabled' => [Finance\SetRuleEnabledTool::class, 'rule', ['rule_id' => 'id', 'enabled' => false]],
    'preview_apply_rules' => [Finance\PreviewApplyRulesTool::class, 'rule', ['rule_id' => 'id']],
    'apply_rules' => [Finance\ApplyRulesTool::class, 'rule', ['rule_id' => 'id']],
    'confirm_transfer_suggestion' => [Finance\ConfirmTransferSuggestionTool::class, 'suggestion', ['suggestion_id' => 'id']],
    'dismiss_transfer_suggestion' => [Finance\DismissTransferSuggestionTool::class, 'suggestion', ['suggestion_id' => 'id']],
    'pair_transfer with their counterpart' => [Finance\PairTransferTool::class, 'transaction', ['transaction_id' => 'own', 'counterpart_id' => 'id']],
    'unpair_transfer' => [Finance\UnpairTransferTool::class, 'transaction', ['transaction_id' => 'id']],
    'mark_transfer' => [Finance\MarkTransferTool::class, 'transaction', ['transaction_id' => 'id']],
    'unmark_transfer' => [Finance\UnmarkTransferTool::class, 'transaction', ['transaction_id' => 'id']],
    'transfer_candidates' => [Finance\TransferCandidatesTool::class, 'transaction', ['transaction_id' => 'id']],
    'get_import_batch' => [Finance\GetImportBatchTool::class, 'batch', ['batch_id' => 'id']],
    'undo_import_batch' => [Finance\UndoImportBatchTool::class, 'batch', ['batch_id' => 'id']],
]);

test('list tools return only the token user\'s rows', function () {
    $owner = User::factory()->create();
    $theirs = Account::factory()->for($owner)->create(['name' => 'Theirs']);
    Transaction::factory()->for($theirs)->create(['description' => 'Their coffee']);
    Payee::factory()->for($owner)->create(['name' => 'Their payee']);
    Rule::factory()->for($owner)->create(['name' => 'Their rule']);
    ImportBatch::factory()->for($owner)->create(['filename' => 'theirs.csv']);
    $mcp = financeMcp($this->user);

    $mcp->tool(Finance\ListAccountsTool::class)->assertStructuredContent(fn (AssertableJson $json) => $json
        ->has('accounts', 1)->where('accounts.0.name', 'Current')->etc());
    $mcp->tool(Finance\ListTransactionsTool::class, ['account' => $theirs->id])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('total', 0)->etc());
    $mcp->tool(Finance\ListTransactionsTool::class)->assertDontSee('Their coffee');
    $mcp->tool(Finance\ListPayeesTool::class)->assertStructuredContent(['payees' => []]);
    $mcp->tool(Finance\ListRulesTool::class)->assertStructuredContent(['rules' => []]);
    $mcp->tool(Finance\ListImportBatchesTool::class)->assertStructuredContent(['batches' => []]);
    $mcp->tool(Finance\ListTransferSuggestionsTool::class)->assertStructuredContent(['suggestions' => []]);
    $mcp->tool(Finance\PreviewRuleMatchesTool::class, ['conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => 'coffee']]])
        ->assertStructuredContent(['count' => 0, 'sample' => []]);
});

test('accounts report balances in Rappen with CHF strings, and a snapshot recorded over MCP moves the balance', function () {
    BalanceSnapshot::factory()->for($this->account)->create(['date' => '2026-10-01', 'balance' => 100000]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-02', 'amount' => -123456]);
    Transaction::factory()->for($this->account)->pending()->create(['date' => '2026-10-03', 'amount' => -450]);
    $mcp = financeMcp($this->user);

    $mcp->tool(Finance\ListAccountsTool::class)->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('accounts.0.balance_today', -23456)
        ->where('accounts.0.balance_today_chf', 'CHF -234.56')
        ->where('accounts.0.pending_total', -450)
        ->where('accounts.0.pending_total_chf', 'CHF -4.50')
        ->etc());

    $mcp->tool(Finance\AddBalanceSnapshotTool::class, ['account_id' => $this->account->id, 'date' => '2026-10-02', 'balance' => -23000])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('account.balance_today', -23000)
            ->where('account.latest_snapshot.difference', 456)
            ->where('account.mismatched_snapshots', 1)
            ->etc());

    $mcp->tool(Finance\GetAccountTool::class, ['account_id' => $this->account->id])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->has('snapshots', 2)
        ->where('snapshots.0.date', '2026-10-02')
        ->where('snapshots.0.source', 'manual')
        ->where('snapshots.0.expected_chf', 'CHF -234.56')
        ->etc());

    $mcp->tool(Finance\AddBalanceSnapshotTool::class, ['account_id' => $this->account->id, 'date' => '2026-10-02', 'balance' => '-230.00'])
        ->assertHasErrors(['balance: Give the balance as an integer in Rappen']);
});

test('transactions filter like the web list and paginate', function () {
    $groceries = Category::query()->ownedBy($this->user)->where('name', 'Groceries')->firstOrFail();
    $migros = Payee::factory()->for($this->user)->create(['name' => 'Migros']);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-01', 'description' => 'MIGROS ZUERICH', 'category_id' => $groceries->id, 'payee_id' => $migros->id]);
    Transaction::factory()->for($this->account)->count(3)->sequence(fn ($sequence) => ['date' => '2026-10-0'.($sequence->index + 2), 'description' => "Kiosk {$sequence->index}"])->create();
    $mcp = financeMcp($this->user);

    $mcp->tool(Finance\ListTransactionsTool::class, ['category' => 'none', 'per_page' => 2, 'page' => 2])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('total', 3)
        ->where('last_page', 2)
        ->has('transactions', 1)
        ->where('transactions.0.description', 'Kiosk 0')
        ->etc());

    $mcp->tool(Finance\ListTransactionsTool::class, ['payee' => $migros->id])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('total', 1)
        ->where('transactions.0.category', 'Groceries')
        ->etc());

    $mcp->tool(Finance\ListTransactionsTool::class, ['search' => 'migros', 'from' => '2026-10-02'])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('total', 0)->etc());

    $mcp->tool(Finance\ListTransactionsTool::class, ['category' => 'all'])
        ->assertHasErrors(['category:']);
});

test('update_transaction sets a manual value and null hands the field back to the rules', function () {
    $groceries = Category::query()->ownedBy($this->user)->where('name', 'Groceries')->firstOrFail();
    $dining = Category::query()->ownedBy($this->user)->where('name', 'Dining')->firstOrFail();
    Rule::factory()->for($this->user)->create([
        'conditions' => [['field' => 'description', 'operator' => 'contains', 'value' => 'migros']],
        'actions' => [['type' => 'set_category', 'value' => $groceries->id]],
    ]);
    $transaction = Transaction::factory()->for($this->account)->create(['description' => 'MIGROS ZUERICH']);
    $mcp = financeMcp($this->user);

    $mcp->tool(Finance\UpdateTransactionTool::class, ['transaction_id' => $transaction->id, 'category_id' => $dining->id])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('transaction.category', 'Dining')
            ->where('transaction.category_source', 'manual')
            ->where('rules_filled', [])
            ->etc());

    $mcp->tool(Finance\UpdateTransactionTool::class, ['transaction_id' => $transaction->id, 'category_id' => null])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('transaction.category', 'Groceries')
            ->where('transaction.category_source', 'rule')
            ->where('rules_filled.0.to_name', 'Groceries')
            ->etc());

    $mcp->tool(Finance\UpdateTransactionTool::class, ['transaction_id' => $transaction->id])
        ->assertHasErrors(['Give category_id, payee_id or both']);
});

test('categories are created, renamed and deleted through the shared actions', function () {
    $mcp = financeMcp($this->user);
    $mcp->tool(Finance\CreateCategoryTool::class, ['name' => ' Pets '])->assertSee('"name":"Pets"');
    $pets = Category::query()->ownedBy($this->user)->where('name', 'Pets')->firstOrFail();
    $transaction = Transaction::factory()->for($this->account)->create(['category_id' => $pets->id, 'category_source' => FieldSource::Manual]);
    $rule = Rule::factory()->for($this->user)->create(['actions' => [['type' => 'set_category', 'value' => $pets->id]]]);

    $mcp->tool(Finance\CreateCategoryTool::class, ['name' => 'Groceries'])
        ->assertHasErrors(['name: You already have a category with this name.']);
    $mcp->tool(Finance\UpdateCategoryTool::class, ['category_id' => $pets->id, 'name' => 'Animals'])->assertSee('"name":"Animals"');
    $mcp->tool(Finance\DeleteCategoryTool::class, ['category_id' => $pets->id])
        ->assertStructuredContent(['deleted' => ['id' => $pets->id, 'name' => 'Animals'], 'uncategorized_transactions' => 1]);

    expect($transaction->fresh()->category_id)->toBeNull()
        ->and($rule->fresh()->enabled)->toBeFalse();
});

test('payees are created, renamed, merged and deleted', function () {
    $mcp = financeMcp($this->user);
    $mcp->tool(Finance\CreatePayeeTool::class, ['name' => 'Coop'])->assertOk();
    $coop = Payee::query()->ownedBy($this->user)->where('name', 'Coop')->firstOrFail();
    $pronto = Payee::factory()->for($this->user)->create(['name' => 'COOP Pronto']);
    $transaction = Transaction::factory()->for($this->account)->create(['payee_id' => $pronto->id]);

    $mcp->tool(Finance\RenamePayeeTool::class, ['payee_id' => $pronto->id, 'name' => 'Coop'])
        ->assertHasErrors(['name: You already have a payee with this name.']);
    $mcp->tool(Finance\MergePayeesTool::class, ['payee_id' => $pronto->id, 'into_payee_id' => $coop->id])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('transactions_moved', 1)->where('payee.transactions_count', 1)->etc());

    expect($transaction->fresh()->payee_id)->toBe($coop->id)
        ->and($pronto->fresh())->toBeNull();

    $mcp->tool(Finance\DeletePayeeTool::class, ['payee_id' => $coop->id])->assertSee('"transactions_without_payee":1');
    expect($transaction->fresh()->payee_id)->toBeNull();
});

test('a rule created over MCP is previewed, dry-run and applied, and manual values survive', function () {
    $groceries = Category::query()->ownedBy($this->user)->where('name', 'Groceries')->firstOrFail();
    $dining = Category::query()->ownedBy($this->user)->where('name', 'Dining')->firstOrFail();
    $first = Transaction::factory()->for($this->account)->create(['date' => '2026-10-01', 'description' => 'MIGROS   Zuerich HB']);
    $second = Transaction::factory()->for($this->account)->create(['date' => '2026-10-02', 'description' => 'Migros Basel']);
    $manual = Transaction::factory()->for($this->account)->create(['date' => '2026-10-03', 'description' => 'Migros Restaurant', 'category_id' => $dining->id, 'category_source' => FieldSource::Manual]);
    Transaction::factory()->for($this->account)->create(['description' => 'Coop']);
    $conditions = [['field' => 'description', 'operator' => 'starts_with', 'value' => 'migros']];
    $mcp = financeMcp($this->user);

    $mcp->tool(Finance\PreviewRuleMatchesTool::class, ['conditions' => $conditions, 'sample' => 1])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('count', 3)->has('sample', 1)->where('sample.0.id', $manual->id)->etc());

    $mcp->tool(Finance\CreateRuleTool::class, [
        'name' => 'Migros',
        'conditions' => $conditions,
        'actions' => [['type' => 'set_category', 'value' => $groceries->id], ['type' => 'set_payee', 'name' => 'Migros']],
    ])->assertOk();
    $rule = Rule::query()->ownedBy($this->user)->sole();

    expect($first->fresh()->category_id)->toBeNull();

    $mcp->tool(Finance\PreviewApplyRulesTool::class, ['rule_id' => $rule->id])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('transactions', 3)
        ->where('changes', ['category' => 2, 'payee' => 3, 'transfer' => 0])
        ->where('sample.0.transaction.category', 'Dining')
        ->where('sample.1.changes.0', ['field' => 'category', 'from' => null, 'to' => $groceries->id, 'from_name' => null, 'to_name' => 'Groceries'])
        ->etc());

    expect($first->fresh()->category_id)->toBeNull();

    $mcp->tool(Finance\ApplyRulesTool::class)->assertStructuredContent(['rule_id' => null, 'transactions' => 3, 'changes' => ['category' => 2, 'payee' => 3, 'transfer' => 0]]);
    $mcp->tool(Finance\ApplyRulesTool::class)->assertStructuredContent(['rule_id' => null, 'transactions' => 0, 'changes' => ['category' => 0, 'payee' => 0, 'transfer' => 0]]);

    expect([$first->fresh()->category_id, $second->fresh()->category_id, $manual->fresh()->category_id])->toBe([$groceries->id, $groceries->id, $dining->id])
        ->and($first->fresh()->category_source)->toBe(FieldSource::Rule);
});

test('rule writes validate with field keys and reorder, toggle and delete work', function () {
    $mcp = financeMcp($this->user);
    $a = Rule::factory()->for($this->user)->create();
    $b = Rule::factory()->for($this->user)->create();

    $mcp->tool(Finance\CreateRuleTool::class, [
        'conditions' => [['field' => 'amount', 'operator' => 'between', 'value' => [-1000, -5000]]],
        'actions' => [['type' => 'set_category', 'value' => 999999]],
    ])->assertHasErrors([
        'conditions.0.value: The first amount must not be larger than the second.',
        'actions.0.value: Choose one of your categories.',
    ]);

    $mcp->tool(Finance\ReorderRulesTool::class, ['rule_ids' => [$b->id]])->assertHasErrors(['order: The order must list each of your rules exactly once.']);
    $mcp->tool(Finance\ReorderRulesTool::class, ['rule_ids' => [$b->id, $a->id]])->assertOk();
    $mcp->tool(Finance\SetRuleEnabledTool::class, ['rule_id' => $a->id, 'enabled' => false])->assertSee('"enabled":false');
    $mcp->tool(Finance\DeleteRuleTool::class, ['rule_id' => $b->id])->assertOk();

    expect(Rule::query()->ownedBy($this->user)->get(['id', 'position', 'enabled'])->toArray())->toBe([['id' => $a->id, 'position' => 2, 'enabled' => false]]);
});

test('transfers are found, suggested, confirmed, dismissed, paired, unpaired, marked and unmarked', function () {
    $savings = Account::factory()->for($this->user)->create(['name' => 'Savings']);
    $out = Transaction::factory()->for($this->account)->create(['date' => '2026-10-01', 'time' => '09:00:00', 'amount' => -5000]);
    $in = Transaction::factory()->for($savings)->create(['date' => '2026-10-01', 'time' => '09:00:00', 'amount' => 5000]);
    $tieOut = Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => -700]);
    $tieIn = Transaction::factory()->for($savings)->count(2)->create(['date' => '2026-10-05', 'amount' => 700]);
    $mcp = financeMcp($this->user);

    $mcp->tool(Finance\FindTransfersTool::class)->assertStructuredContent(['paired' => 1, 'suggested' => 2]);
    expect($out->fresh()->transfer_counterpart_id)->toBe($in->id);

    $mcp->tool(Finance\ListTransferSuggestionsTool::class)->assertStructuredContent(fn (AssertableJson $json) => $json
        ->has('suggestions', 2)->where('suggestions.0.outflow.id', $tieOut->id)->etc());
    $suggestions = TransferSuggestion::query()->ownedBy($this->user)->orderBy('id')->get();

    $mcp->tool(Finance\DismissTransferSuggestionTool::class, ['suggestion_id' => $suggestions[1]->id])->assertOk();
    $mcp->tool(Finance\ConfirmTransferSuggestionTool::class, ['suggestion_id' => $suggestions[0]->id])->assertOk();
    expect($tieOut->fresh()->transfer_counterpart_id)->toBe($suggestions[0]->inflow_id)
        ->and($tieOut->fresh()->transfer_source)->toBe(TransferSource::Manual);

    $mcp->tool(Finance\UnpairTransferTool::class, ['transaction_id' => $out->id])->assertOk();
    $mcp->tool(Finance\TransferCandidatesTool::class, ['transaction_id' => $out->id])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->has('candidates', 1)->where('candidates.0.id', $in->id)->etc());
    $mcp->tool(Finance\PairTransferTool::class, ['transaction_id' => $out->id, 'counterpart_id' => $in->id])->assertOk();
    $mcp->tool(Finance\MarkTransferTool::class, ['transaction_id' => $out->id])
        ->assertHasErrors(['transfer: This transaction is paired. Unpair it first.']);
    $mcp->tool(Finance\UnmarkTransferTool::class, ['transaction_id' => $out->id])
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('transaction.is_transfer', false)->where('former_counterpart.id', $in->id)->etc());

    $lone = $tieIn->firstWhere('id', '!=', $suggestions[0]->inflow_id);
    $mcp->tool(Finance\MarkTransferTool::class, ['transaction_id' => $lone->id])->assertSee('"is_transfer":true');
    expect($lone->fresh()->transfer_source)->toBe(TransferSource::Manual);
});

test('import_csv imports through saved mappings, skips re-imported rows and refuses an unmapped file', function () {
    ImportMapping::factory()->for($this->account)->create();
    $content = (string) file_get_contents(base_path('tests/Fixtures/finance/simple.csv'));
    $mcp = financeMcp($this->user);

    $mcp->tool(Finance\ImportCsvTool::class, ['content' => $content, 'filename' => 'simple.csv'])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('batch.filename', 'simple.csv')->where('batch.summary.created', 3)->etc());
    $mcp->tool(Finance\ImportCsvTool::class, ['content' => $content])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('batch.filename', 'mcp-import.csv')->where('batch.summary.created', 0)->where('batch.summary.skipped_duplicate', 3)->etc());

    $mcp->tool(Finance\ImportCsvTool::class, ['content' => "Booked,Text,CHF\n2026-10-01,Salary,1000.00\n"])
        ->assertHasErrors(['No saved import mapping matches this file\'s columns']);
    $mcp->tool(Finance\ImportCsvTool::class, ['content' => "Date,Description,Amount\n2026-13-45,Salary,1000.00\n"])
        ->assertHasErrors(['1 of 1 rows could not be read. Nothing was imported.', 'Line 2:']);

    expect(Transaction::query()->ownedBy($this->user)->count())->toBe(3)
        ->and(ImportBatch::query()->ownedBy($this->user)->count())->toBe(2);
});

test('import batches are listed and undone newest first', function () {
    ImportMapping::factory()->for($this->account)->create();
    $mcp = financeMcp($this->user);
    $mcp->tool(Finance\ImportCsvTool::class, ['content' => (string) file_get_contents(base_path('tests/Fixtures/finance/simple.csv'))])->assertOk();
    $mcp->tool(Finance\ImportCsvTool::class, ['content' => "Date,Description,Amount\n2026-10-20,Bonus,200.00\n"])->assertOk();
    [$later, $first] = ImportBatch::query()->ownedBy($this->user)->latest('id')->get()->all();

    $mcp->tool(Finance\ListImportBatchesTool::class)->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('batches.0.id', $later->id)->where('batches.1.blocked_by.id', $later->id)->etc());
    $mcp->tool(Finance\UndoImportBatchTool::class, ['batch_id' => $first->id])
        ->assertHasErrors(["Undo the later import batch {$later->id}"]);
    $mcp->tool(Finance\UndoImportBatchTool::class, ['batch_id' => $later->id])->assertOk();
    $mcp->tool(Finance\UndoImportBatchTool::class, ['batch_id' => $later->id])->assertHasErrors(['was already undone']);
    $mcp->tool(Finance\UndoImportBatchTool::class, ['batch_id' => $first->id])->assertOk();
    $mcp->tool(Finance\GetImportBatchTool::class, ['batch_id' => $first->id])->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('batch.transactions_count', 0)->whereType('batch.undone_at', 'string')->etc());

    expect(Transaction::query()->ownedBy($this->user)->where('status', TransactionStatus::Completed)->count())->toBe(0);
});

test('the categorization prompt explains the workflow and every rule condition field and action', function () {
    $response = financeMcp($this->user)->prompt(CategorizeTransactionsPrompt::class);

    $response->assertOk()->assertSee(['list_transactions with category "none"', 'preview_rule_matches', 'apply_rules']);

    foreach ([...RuleConditionField::cases(), ...RuleActionType::cases()] as $case) {
        $response->assertSee($case->value);
    }
});
