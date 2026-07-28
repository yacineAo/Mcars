<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PayrollRunResource\Pages;

use App\Filament\Admin\Resources\PayrollRunResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayrollRun extends CreateRecord
{
    protected static string $resource = PayrollRunResource::class;
}
