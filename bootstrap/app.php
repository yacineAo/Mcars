<?php

declare(strict_types=1);

use App\Filament\Admin\Panels\AdminPanelProvider;
use App\Http\Middleware\SetLocaleFromUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The `web` group, not the global stack: global middleware runs before
        // StartSession, so Auth::user() there is always null and the locale would
        // never be applied. Panel routes get it from the panel's own middleware list.
        $middleware->appendToGroup('web', SetLocaleFromUser::class);
    })
    // One panel. The system is staff-only: customers and car owners are records
    // the office manages, never accounts that log in.
    ->withProviders([
        AdminPanelProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->reportable(function (QueryException $e): void {
            $message = $e->getMessage();

            $isUpdateRejection = str_contains(
                $message,
                'Transactions are append-only. Use AccountingService::reverse() to correct mistakes.',
            );
            $isDeleteRejection = str_contains(
                $message,
                'Transactions are append-only. Deletion is not permitted.',
            );

            if (! $isUpdateRejection && ! $isDeleteRejection) {
                return;
            }

            $operation = $isUpdateRejection ? 'update' : 'delete';

            activity('ledger')
                ->causedBy(Auth::user())
                ->withProperties([
                    'operation' => $operation,
                    'pdo_code' => $e->getCode(),
                    'sql' => $e->getSql(),
                    'bindings' => $e->getBindings(),
                    'attempted_at' => now()->toIso8601String(),
                    'ip' => optional(request())->ip(),
                    'user_agent' => optional(request())->userAgent(),
                ])
                ->log("rejected_ledger_mutation:{$operation}");
        });
    })->create();
