<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Rules\ManageRules;
use App\Actions\Finance\Rules\RuleEngine;
use App\Http\Controllers\Controller;
use App\Models\Finance\Account;
use App\Models\Finance\Category;
use App\Models\Finance\Payee;
use App\Models\Finance\Rule;
use App\Models\Finance\RuleConditionField;
use App\Models\Finance\RuleOperator;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RuleController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('finance/rules/index', [
            'rules' => Rule::query()->ownedBy($user)->orderBy('position')->orderBy('id')->get()
                ->map(fn (Rule $rule): array => self::present($rule))->all(),
            ...self::options($user),
        ]);
    }

    /**
     * The new-rule form. `?transaction=<id>` pre-fills a `description contains` condition with
     * that transaction's raw description.
     */
    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $from = $request->filled('transaction')
            ? Transaction::query()->ownedBy($user)->findOrFail($request->integer('transaction'))
            : null;

        return Inertia::render('finance/rules/edit', [
            'rule' => null,
            'initial' => [
                'name' => '',
                'enabled' => true,
                'conditions' => [[
                    'field' => RuleConditionField::Description->value,
                    'operator' => RuleOperator::Contains->value,
                    'value' => $from === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $from->description)),
                ]],
                'actions' => [],
            ],
            ...self::options($user),
        ]);
    }

    public function store(Request $request, ManageRules $rules): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rules->create($user, $request->only(['name', 'enabled', 'conditions', 'actions']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule created.')]);

        return to_route('finance.rules.index');
    }

    public function edit(Request $request, Rule $rule): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('finance/rules/edit', [
            'rule' => self::present($rule),
            'initial' => [
                'name' => (string) $rule->name,
                'enabled' => $rule->enabled,
                'conditions' => $rule->conditions,
                'actions' => $rule->actions,
            ],
            ...self::options($user),
        ]);
    }

    public function update(Request $request, Rule $rule, ManageRules $rules): RedirectResponse
    {
        $rules->update($rule, $request->only(['name', 'enabled', 'conditions', 'actions']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule saved.')]);

        return to_route('finance.rules.index');
    }

    public function toggle(Request $request, Rule $rule, ManageRules $rules): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $rules->setEnabled($rule, $request->boolean('enabled'));

        return back();
    }

    public function reorder(Request $request, ManageRules $rules): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']]);
        $rules->reorder($user, $request->input('order'));

        return back();
    }

    public function destroy(Rule $rule, ManageRules $rules): RedirectResponse
    {
        $rules->delete($rule);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule deleted.')]);

        return to_route('finance.rules.index');
    }

    /**
     * Live preview for the rule form: which transactions the (unsaved) conditions match.
     */
    public function matches(Request $request, ManageRules $rules, RuleEngine $engine): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $engine->matching($user, $rules->validateConditions($user, $request->only(['conditions'])));

        return response()->json([
            'count' => $result['count'],
            'sample' => array_map(fn (Transaction $transaction): array => TransactionController::present($transaction), $result['sample']),
        ]);
    }

    /**
     * Dry run of applying every enabled rule to existing transactions.
     */
    public function reviewAll(Request $request, RuleEngine $engine): Response
    {
        return $this->review($request, $engine, null);
    }

    public function applyAll(Request $request, RuleEngine $engine): RedirectResponse
    {
        return $this->apply($request, $engine, null);
    }

    /**
     * Dry run of applying the rules to the transactions this rule matches.
     */
    public function reviewOne(Request $request, Rule $rule, RuleEngine $engine): Response
    {
        return $this->review($request, $engine, $rule);
    }

    public function applyOne(Request $request, Rule $rule, RuleEngine $engine): RedirectResponse
    {
        return $this->apply($request, $engine, $rule);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Rule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'position' => $rule->position,
            'enabled' => $rule->enabled,
            'conditions' => $rule->conditions,
            'actions' => $rule->actions,
        ];
    }

    private function review(Request $request, RuleEngine $engine, ?Rule $rule): Response
    {
        /** @var User $user */
        $user = $request->user();

        $preview = $engine->preview($user, $rule);
        $names = [
            'category' => Category::query()->ownedBy($user)->pluck('name', 'id')->all(),
            'payee' => Payee::query()->ownedBy($user)->pluck('name', 'id')->all(),
        ];

        return Inertia::render('finance/rules/apply', [
            'rule' => $rule === null ? null : self::present($rule),
            'transactions' => $preview['transactions'],
            'changes' => $preview['changes'],
            'sample' => array_map(fn (array $entry): array => [
                'transaction' => TransactionController::present($entry['transaction']->loadMissing(['category', 'payee'])),
                'changes' => collect($entry['changes'])->map(fn (array $change, string $field): array => [
                    'field' => $field,
                    'from' => $change['from'] === null ? null : ($names[$field][$change['from']] ?? null),
                    'to' => $names[$field][$change['to']] ?? null,
                ])->values()->all(),
            ], $preview['sample']),
            ...self::options($user),
        ]);
    }

    private function apply(Request $request, RuleEngine $engine, ?Rule $rule): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $engine->apply($user, $rule);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(
            'Rules changed :count transaction (:category categories, :payee payees).|Rules changed :count transactions (:category categories, :payee payees).',
            $result['transactions'],
            ['category' => $result['changes']['category'], 'payee' => $result['changes']['payee']],
        )]);

        return to_route('finance.rules.index');
    }

    /**
     * @return array{accounts: array<int, array{id: int, name: string}>, categories: array<int, array{id: int, name: string}>, payees: array<int, array{id: int, name: string}>}
     */
    private static function options(User $user): array
    {
        return [
            'accounts' => Account::query()->ownedBy($user)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name])->values()->all(),
            'categories' => TransactionController::categoryOptions($user),
            'payees' => TransactionController::payeeOptions($user),
        ];
    }
}
