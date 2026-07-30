<?php

declare(strict_types=1);

use App\Enums\Locale;
use App\Http\Controllers\DocumentMediaController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/exports/{id}/download', [ExportController::class, 'download'])
        ->name('exports.download')
        ->whereNumber('id');

    // ADR-009: private-disk files served through a policy-checked controller. The caller
    // receives a short-lived signed URL; the controller checks the signature and the
    // fleet.view permission before serving the bytes.
    Route::get('/media/car-documents/{carDocument}/download', [DocumentMediaController::class, 'download'])
        ->name('media.car-documents.download')
        ->whereNumber('carDocument');

    // POST: switching language writes to the user row. The constraint comes from the
    // enum so the route and Locale cannot drift apart.
    Route::post('/locale/{locale}', [LocaleController::class, 'switch'])
        ->name('locale.switch')
        ->whereIn('locale', Locale::values());
});
