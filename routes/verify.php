<?php

use App\Http\Controllers\VerifyLoginController;
use Illuminate\Support\Facades\Route;

/*
 * Loaded only in local and testing environments by VerificationServiceProvider.
 */
Route::middleware(['web', 'signed:relative'])->group(function () {
    Route::get('_verify/login/{user}', VerifyLoginController::class)->name('verify.login');
});
