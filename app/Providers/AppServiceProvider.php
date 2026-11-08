<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureErrorPages();
    }

    /**
     * Render HTTP errors inside the app instead of Laravel's bare error page. JSON responses (API,
     * MCP and `useHttp` requests) stay JSON. With debug mode on, server errors keep Laravel's
     * exception page.
     */
    protected function configureErrorPages(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response): mixed {
            if ($response->response instanceof JsonResponse) {
                return null;
            }

            $status = $response->statusCode();

            if ($status === 419) {
                Inertia::flash('toast', ['type' => 'warning', 'message' => 'Your session expired. Try that again.']);

                return back();
            }

            if (in_array($status, [403, 404], true) || (in_array($status, [500, 503], true) && ! config('app.debug'))) {
                return $response->render('error', ['status' => $status])->withSharedData();
            }

            return null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
