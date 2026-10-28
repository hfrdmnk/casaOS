<?php

namespace App\Providers;

use App\Verification\Contracts\Inspector;
use App\Verification\Contracts\ScenarioState;
use App\Verification\Finance\FinanceEmpty;
use App\Verification\Finance\FinanceImported;
use App\Verification\Finance\FinanceInspector;
use App\Verification\Finance\FinanceRules;
use App\Verification\Finance\FinanceSubscriptions;
use App\Verification\Finance\FinanceTransfers;
use Illuminate\Support\ServiceProvider;

/**
 * The finance module: plugs its scenario states and inspector into the `verify:*` levers.
 */
class FinanceServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([FinanceEmpty::class, FinanceImported::class, FinanceRules::class, FinanceTransfers::class, FinanceSubscriptions::class], ScenarioState::TAG);
        $this->app->tag([FinanceInspector::class], Inspector::TAG);
    }
}
