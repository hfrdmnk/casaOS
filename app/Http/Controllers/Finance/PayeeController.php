<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Payees\ManagePayees;
use App\Http\Controllers\Controller;
use App\Models\Finance\Payee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayeeController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('finance/payees/index', [
            'payees' => Payee::query()->ownedBy($user)->withCount('transactions')->orderBy('name')->get()
                ->map(fn (Payee $payee): array => [
                    'id' => $payee->id,
                    'name' => $payee->name,
                    'transactions_count' => $payee->transactions_count,
                ])->all(),
        ]);
    }

    public function store(Request $request, ManagePayees $payees): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $payees->create($user, $request->string('name')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payee created.')]);

        return to_route('finance.payees.index');
    }

    public function update(Request $request, Payee $payee, ManagePayees $payees): RedirectResponse
    {
        $payees->rename($payee, $request->string('name')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payee renamed.')]);

        return to_route('finance.payees.index');
    }

    /**
     * Merge the payee into another one: its transactions and rules move there.
     */
    public function merge(Request $request, Payee $payee, ManagePayees $payees): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['into' => ['required', 'integer']]);
        $into = Payee::query()->ownedBy($user)->findOrFail($request->integer('into'));

        $moved = $payees->merge($payee, $into);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Merged into :name; :count transaction moved.|Merged into :name; :count transactions moved.', $moved, ['name' => $into->name])]);

        return to_route('finance.payees.index');
    }

    /**
     * Delete a payee. Its transactions become payee-less.
     */
    public function destroy(Payee $payee, ManagePayees $payees): RedirectResponse
    {
        $payees->delete($payee);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payee deleted.')]);

        return to_route('finance.payees.index');
    }
}
