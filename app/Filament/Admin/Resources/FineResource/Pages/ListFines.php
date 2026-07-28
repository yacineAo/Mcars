<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FineResource\Pages;

use App\Filament\Admin\Resources\FineResource;
use Filament\Resources\Pages\ListRecords;

class ListFines extends ListRecords
{
    protected static string $resource = FineResource::class;
}
