<?php

declare(strict_types=1);

use App\Enums\Locale;
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

    // POST: switching language writes to the user row. The constraint comes from the
    // enum so the route and Locale cannot drift apart.
    Route::post('/locale/{locale}', [LocaleController::class, 'switch'])
        ->name('locale.switch')
        ->whereIn('locale', Locale::values());
});
