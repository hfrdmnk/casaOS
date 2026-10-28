<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Finance\Categories\ManageCategories;
use App\Http\Controllers\Controller;
use App\Models\Finance\Category;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('finance/categories/index', [
            'categories' => Category::query()->ownedBy($user)->withCount('transactions')->orderBy('name')->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'transactions_count' => $category->transactions_count,
                ])->all(),
        ]);
    }

    public function store(Request $request, ManageCategories $categories): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $categories->create($user, $request->string('name')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return to_route('finance.categories.index');
    }

    public function update(Request $request, Category $category, ManageCategories $categories): RedirectResponse
    {
        $categories->rename($category, $request->string('name')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category renamed.')]);

        return to_route('finance.categories.index');
    }

    /**
     * Delete a category. Its transactions become uncategorized and rules stop setting it.
     */
    public function destroy(Category $category, ManageCategories $categories): RedirectResponse
    {
        $categories->delete($category);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category deleted.')]);

        return to_route('finance.categories.index');
    }
}
