<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OwnerInstallmentResource\Pages;

use App\Filament\Admin\Resources\OwnerInstallmentResource;
use Filament\Resources\Pages\ListRecords;

class ListOwnerInstallments extends ListRecords
{
    protected static string $resource = OwnerInstallmentResource::class;
}
