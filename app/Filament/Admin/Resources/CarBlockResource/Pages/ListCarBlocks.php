<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarBlockResource\Pages;

use App\Filament\Admin\Resources\CarBlockResource;
use Filament\Resources\Pages\ListRecords;

class ListCarBlocks extends ListRecords
{
    protected static string $resource = CarBlockResource::class;
}
