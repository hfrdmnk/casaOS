<?php

namespace App\Actions\Finance\Categories;

use App\Actions\Finance\Rules\ManageRules;
use App\Models\Finance\Category;
use App\Models\Finance\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as ValidationRule;

/**
 * Creates, renames and deletes a user's categories. The web controllers and the MCP tools call
 * this class. Names are unique per user (validation errors are thrown as a ValidationException
 * on `name`).
 */
final class ManageCategories
{
    public function __construct(private ManageRules $rules) {}

    public function create(User $user, string $name): Category
    {
        $category = new Category(['name' => $this->validName($user, $name)]);
        $category->user_id = $user->id;
        $category->save();

        return $category;
    }

    public function rename(Category $category, string $name): Category
    {
        $category->update(['name' => $this->validName($category->user, $name, $category)]);

        return $category;
    }

    /**
     * Delete a category. Its transactions become uncategorized and rules stop setting it (see
     * ManageRules::forget()).
     */
    public function delete(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            Transaction::query()->where('category_id', $category->id)->update(['category_id' => null, 'category_source' => null]);
            $this->rules->forget($category->user, 'category', $category->id);
            $category->delete();
        });
    }

    private function validName(User $user, string $name, ?Category $ignore = null): string
    {
        $data = Validator::make(['name' => trim($name)], [
            'name' => ['required', 'string', 'max:100', ValidationRule::unique('categories', 'name')->where('user_id', $user->id)->ignore($ignore?->id)],
        ], ['name.unique' => 'You already have a category with this name.'])->validate();

        return $data['name'];
    }
}
