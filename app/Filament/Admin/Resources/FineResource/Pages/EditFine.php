<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FineResource\Pages;

use App\Filament\Admin\Resources\FineResource;
use Filament\Resources\Pages\EditRecord;

class EditFine extends EditRecord
{
    protected static string $resource = FineResource::class;
}
