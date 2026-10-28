<?php

use App\Http\Controllers\Finance\AccountController;
use App\Http\Controllers\Finance\BalanceSnapshotController;
use App\Http\Controllers\Finance\CategoryController;
use App\Http\Controllers\Finance\ImportBatchController;
use App\Http\Controllers\Finance\ImportController;
use App\Http\Controllers\Finance\ImportMappingController;
use App\Http\Controllers\Finance\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('finance')->name('finance.')->group(function () {
    Route::redirect('/', '/finance/accounts');

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('accounts/{account}/archive', [AccountController::class, 'archive'])->name('accounts.archive');
    Route::delete('accounts/{account}/archive', [AccountController::class, 'unarchive'])->name('accounts.unarchive');

    Route::post('accounts/{account}/snapshots', [BalanceSnapshotController::class, 'store'])->name('snapshots.store');
    Route::delete('snapshots/{snapshot}', [BalanceSnapshotController::class, 'destroy'])->name('snapshots.destroy');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('import', [ImportController::class, 'index'])->name('import.index');
    Route::post('import', [ImportController::class, 'store'])->name('import.store');
    Route::get('import/{upload}', [ImportController::class, 'show'])->whereUuid('upload')->name('import.show');
    Route::post('import/{upload}/preview', [ImportController::class, 'preview'])->whereUuid('upload')->name('import.preview');
    Route::post('import/{upload}/commit', [ImportController::class, 'commit'])->whereUuid('upload')->name('import.commit');
    Route::post('import/{upload}/mappings', [ImportMappingController::class, 'store'])->whereUuid('upload')->name('mappings.store');

    Route::get('mappings/{mapping}/edit', [ImportMappingController::class, 'edit'])->name('mappings.edit');
    Route::put('mappings/{mapping}', [ImportMappingController::class, 'update'])->name('mappings.update');
    Route::delete('mappings/{mapping}', [ImportMappingController::class, 'destroy'])->name('mappings.destroy');

    Route::get('batches/{batch}', [ImportBatchController::class, 'show'])->name('batches.show');
    Route::post('batches/{batch}/undo', [ImportBatchController::class, 'undo'])->name('batches.undo');
});
