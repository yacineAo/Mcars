<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorResource\Pages;

use App\Filament\Admin\Resources\VendorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
