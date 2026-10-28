<?php

namespace App\Actions\Finance\Payees;

use App\Actions\Finance\Rules\ManageRules;
use App\Models\Finance\Payee;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\Validation\ValidationException;

/**
 * Creates, renames, merges and deletes a user's payees. Names are unique per user (validation
 * errors are thrown as a ValidationException on `name`).
 */
final class ManagePayees
{
    public function __construct(private ManageRules $rules) {}

    public function create(User $user, string $name): Payee
    {
        $payee = new Payee(['name' => $this->validName($user, $name)]);
        $payee->user_id = $user->id;
        $payee->save();

        return $payee;
    }

    /**
     * The user's payee with this name, created when missing.
     */
    public function findOrCreate(User $user, string $name): Payee
    {
        return Payee::query()->ownedBy($user)->where('name', trim($name))->first() ?? $this->create($user, $name);
    }

    public function rename(Payee $payee, string $name): Payee
    {
        $payee->update(['name' => $this->validName($payee->user, $name, $payee)]);

        return $payee;
    }

    /**
     * Move every transaction and rule of `$from` to `$into`, then delete `$from`. Returns the
     * number of transactions moved.
     */
    public function merge(Payee $from, Payee $into): int
    {
        if ($from->is($into) || $from->user_id !== $into->user_id) {
            throw ValidationException::withMessages(['into' => 'Choose another of your payees.']);
        }

        return DB::transaction(function () use ($from, $into): int {
            $moved = Transaction::query()->where('payee_id', $from->id)->update(['payee_id' => $into->id]);
            $this->rules->replacePayee($from->user, $from->id, $into->id);
            $from->delete();

            return $moved;
        });
    }

    /**
     * Delete a payee. Its transactions become payee-less and their payee goes back to the rules;
     * rules lose actions that set it (see ManageRules::forget()).
     */
    public function delete(Payee $payee): void
    {
        DB::transaction(function () use ($payee): void {
            Transaction::query()->where('payee_id', $payee->id)->update(['payee_id' => null, 'payee_source' => null]);
            $this->rules->forget($payee->user, 'payee', $payee->id);
            $payee->delete();
        });
    }

    private function validName(User $user, string $name, ?Payee $ignore = null): string
    {
        $data = Validator::make(['name' => trim($name)], [
            'name' => ['required', 'string', 'max:100', ValidationRule::unique('payees', 'name')->where('user_id', $user->id)->ignore($ignore?->id)],
        ], ['name.unique' => 'You already have a payee with this name.'])->validate();

        return $data['name'];
    }
}
