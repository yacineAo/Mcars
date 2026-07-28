<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages;

use App\Filament\Admin\Resources\CarOwnershipAgreementResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCarOwnershipAgreement extends CreateRecord
{
    protected static string $resource = CarOwnershipAgreementResource::class;
}
