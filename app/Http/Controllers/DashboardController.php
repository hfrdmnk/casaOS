<?php

namespace App\Http\Controllers;

use App\Actions\Finance\Dashboard\DashboardSummary;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The finance overview for one month (`?month=Y-m`, this month by default).
     */
    public function __invoke(Request $request, DashboardSummary $summary): Response
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $request->string('month')->toString() ?: today()->format('Y-m');

        return Inertia::render('dashboard', [
            'summary' => $summary($user, $month),
        ]);
    }
}
