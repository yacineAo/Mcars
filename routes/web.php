<?php

declare(strict_types=1);

use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/exports/{id}/download', [ExportController::class, 'download'])
        ->name('exports.download')
        ->whereNumber('id');
});
