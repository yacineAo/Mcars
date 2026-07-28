<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnerResource\Pages;

use App\Filament\Admin\Resources\CarOwnerResource;
use Filament\Resources\Pages\EditRecord;

class EditCarOwner extends EditRecord
{
    protected static string $resource = CarOwnerResource::class;
}
