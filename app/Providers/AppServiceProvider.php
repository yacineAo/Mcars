<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CarDocument;
use App\Observers\CarDocumentObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        CarDocument::observe(CarDocumentObserver::class);
    }
}
