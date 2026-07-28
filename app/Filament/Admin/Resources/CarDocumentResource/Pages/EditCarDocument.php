<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarDocumentResource\Pages;

use App\Filament\Admin\Resources\CarDocumentResource;
use Filament\Resources\Pages\EditRecord;

class EditCarDocument extends EditRecord
{
    protected static string $resource = CarDocumentResource::class;
}
