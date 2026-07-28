<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommissionResource\Pages;

use App\Filament\Admin\Resources\CommissionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCommission extends CreateRecord
{
    protected static string $resource = CommissionResource::class;
}
