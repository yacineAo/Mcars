<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\Pages;

use App\Filament\Admin\Resources\CarResource;
use Filament\Resources\Pages\EditRecord;

class EditCar extends EditRecord
{
    protected static string $resource = CarResource::class;
}
