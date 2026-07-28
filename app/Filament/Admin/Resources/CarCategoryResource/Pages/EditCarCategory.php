<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarCategoryResource\Pages;

use App\Filament\Admin\Resources\CarCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditCarCategory extends EditRecord
{
    protected static string $resource = CarCategoryResource::class;
}
