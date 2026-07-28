<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorResource\Pages;

use App\Filament\Admin\Resources\VendorResource;
use Filament\Resources\Pages\EditRecord;

class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;
}
