<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Import\ImportUpload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ImportMappingRequest;
use App\Models\Finance\Account;
use App\Models\Finance\AccountType;
use App\Models\Finance\ImportMapping;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class ImportMappingController extends Controller
{
    /**
     * Save a mapping from the wizard, creating its account when the user named a new one.
     */
    public function store(ImportMappingRequest $request, string $upload): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = ImportUpload::findOrFail($user, $upload);

        $mapping = new ImportMapping($request->mappingAttributes());
        $mapping->user_id = $user->id;
        $mapping->account_id = $this->accountId($request, $user);
        $mapping->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mapping saved.')]);

        return to_route('finance.import.show', $file->id);
    }

    public function edit(ImportMapping $mapping): Response
    {
        /** @var User $user */
        $user = $mapping->user;

        return Inertia::render('finance/mappings/edit', [
            'mapping' => [
                ...Arr::except($mapping->toArray(), ['user_id', 'signature', 'created_at', 'updated_at']),
                'amount_mode' => $mapping->amount_mode->value,
            ],
            'accounts' => Account::query()->ownedBy($user)->active()->orderBy('name')->get()
                ->filter(fn (Account $account): bool => $account->type->isImportable() || $account->id === $mapping->account_id)
                ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->value])
                ->values()->all(),
            'types' => array_values(array_filter(AccountType::options(), fn (array $type): bool => $type['importable'])),
        ]);
    }

    /**
     * Change how rows are read. The file shape (encoding, delimiter, preamble, header) is fixed:
     * a different shape is a different mapping.
     */
    public function update(ImportMappingRequest $request, ImportMapping $mapping): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $mapping->fill(Arr::except($request->mappingAttributes(), ['encoding', 'delimiter', 'skip_lines', 'header']));
        $mapping->account_id = $this->accountId($request, $user);
        $mapping->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mapping updated.')]);

        return to_route('finance.import.index');
    }

    /**
     * Delete a mapping. Transactions it imported stay.
     */
    public function destroy(ImportMapping $mapping): RedirectResponse
    {
        $mapping->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mapping deleted.')]);

        return to_route('finance.import.index');
    }

    private function accountId(ImportMappingRequest $request, User $user): int
    {
        if ($request->filled('new_account_name')) {
            return $user->accounts()->create([
                'name' => $request->validated('new_account_name'),
                'type' => $request->validated('new_account_type'),
            ])->id;
        }

        return $request->integer('account_id');
    }
}
