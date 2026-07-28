<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\Pages;

use App\Filament\Admin\Resources\CarResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCar extends CreateRecord
{
    protected static string $resource = CarResource::class;
}
