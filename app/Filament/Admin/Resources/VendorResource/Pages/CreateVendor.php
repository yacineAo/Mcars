<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorResource\Pages;

use App\Filament\Admin\Resources\VendorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVendor extends CreateRecord
{
    protected static string $resource = VendorResource::class;
}
