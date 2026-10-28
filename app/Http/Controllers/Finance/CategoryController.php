<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CategoryRequest;
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

    public function store(CategoryRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->categories()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category created.')]);

        return to_route('finance.categories.index');
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $category->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category renamed.')]);

        return to_route('finance.categories.index');
    }

    /**
     * Delete a category. Its transactions become uncategorized.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Category deleted.')]);

        return to_route('finance.categories.index');
    }
}
