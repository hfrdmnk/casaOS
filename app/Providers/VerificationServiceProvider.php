<?php

namespace App\Providers;

use App\Verification\CaptureScenarioMail;
use App\Verification\Contracts\Inspector;
use App\Verification\Contracts\ScenarioState;
use App\Verification\Inspectors\AccountInspector;
use App\Verification\States\TwoFactorEnabled;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the `verify:*` levers used by the verify-casaos agent skill.
 *
 * Modules add their own scenario states and inspectors by tagging them in their
 * own service provider with ScenarioState::TAG and Inspector::TAG.
 */
class VerificationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag([TwoFactorEnabled::class], ScenarioState::TAG);
        $this->app->tag([AccountInspector::class], Inspector::TAG);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            return;
        }

        $this->loadRoutesFrom(base_path('routes/verify.php'));

        Event::listen(MessageSent::class, CaptureScenarioMail::class);
    }
}
