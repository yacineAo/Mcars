<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ContractTemplateResource\Pages;

use App\Filament\Admin\Resources\ContractTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContractTemplate extends CreateRecord
{
    protected static string $resource = ContractTemplateResource::class;
}
