<?php

use App\Http\Controllers\Finance\AccountController;
use App\Http\Controllers\Finance\BalanceSnapshotController;
use App\Http\Controllers\Finance\CategoryController;
use App\Http\Controllers\Finance\ImportBatchController;
use App\Http\Controllers\Finance\ImportController;
use App\Http\Controllers\Finance\ImportMappingController;
use App\Http\Controllers\Finance\PayeeController;
use App\Http\Controllers\Finance\RuleController;
use App\Http\Controllers\Finance\TransactionController;
use App\Http\Controllers\Finance\TransferController;
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
    Route::get('transactions/{transaction}/transfer/candidates', [TransferController::class, 'candidates'])->name('transactions.transfer-candidates');
    Route::post('transactions/{transaction}/transfer/pair', [TransferController::class, 'pair'])->name('transactions.pair');
    Route::delete('transactions/{transaction}/transfer/pair', [TransferController::class, 'unpair'])->name('transactions.unpair');
    Route::post('transactions/{transaction}/transfer', [TransferController::class, 'mark'])->name('transactions.mark-transfer');
    Route::delete('transactions/{transaction}/transfer', [TransferController::class, 'unmark'])->name('transactions.unmark-transfer');

    Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
    Route::post('transfers/find', [TransferController::class, 'find'])->name('transfers.find');
    Route::post('transfers/suggestions/{suggestion}/confirm', [TransferController::class, 'confirm'])->name('transfers.confirm');
    Route::post('transfers/suggestions/{suggestion}/dismiss', [TransferController::class, 'dismiss'])->name('transfers.dismiss');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('payees', [PayeeController::class, 'index'])->name('payees.index');
    Route::post('payees', [PayeeController::class, 'store'])->name('payees.store');
    Route::patch('payees/{payee}', [PayeeController::class, 'update'])->name('payees.update');
    Route::post('payees/{payee}/merge', [PayeeController::class, 'merge'])->name('payees.merge');
    Route::delete('payees/{payee}', [PayeeController::class, 'destroy'])->name('payees.destroy');

    Route::get('rules', [RuleController::class, 'index'])->name('rules.index');
    Route::get('rules/create', [RuleController::class, 'create'])->name('rules.create');
    Route::post('rules', [RuleController::class, 'store'])->name('rules.store');
    Route::put('rules/order', [RuleController::class, 'reorder'])->name('rules.reorder');
    Route::post('rules/matches', [RuleController::class, 'matches'])->name('rules.matches');
    Route::get('rules/apply', [RuleController::class, 'reviewAll'])->name('rules.review-all');
    Route::post('rules/apply', [RuleController::class, 'applyAll'])->name('rules.apply-all');
    Route::get('rules/{rule}/edit', [RuleController::class, 'edit'])->name('rules.edit');
    Route::put('rules/{rule}', [RuleController::class, 'update'])->name('rules.update');
    Route::patch('rules/{rule}/enabled', [RuleController::class, 'toggle'])->name('rules.toggle');
    Route::delete('rules/{rule}', [RuleController::class, 'destroy'])->name('rules.destroy');
    Route::get('rules/{rule}/apply', [RuleController::class, 'reviewOne'])->name('rules.review');
    Route::post('rules/{rule}/apply', [RuleController::class, 'applyOne'])->name('rules.apply');

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
