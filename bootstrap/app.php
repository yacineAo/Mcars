<?php

declare(strict_types=1);

use App\Filament\Admin\Panels\AdminPanelProvider;
use App\Filament\Client\Panels\ClientPanelProvider;
use App\Filament\Owner\Panels\OwnerPanelProvider;
use App\Http\Middleware\EnsureUserIsCarOwner;
use App\Http\Middleware\EnsureUserIsClient;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ensure-user-is-car-owner' => EnsureUserIsCarOwner::class,
            'ensure-user-is-client' => EnsureUserIsClient::class,
        ]);
    })
    ->withProviders([
        AdminPanelProvider::class,
        OwnerPanelProvider::class,
        ClientPanelProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
