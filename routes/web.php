<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/finance.php';
