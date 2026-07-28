<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentScheduleResource\Pages;

use App\Filament\Admin\Resources\PaymentScheduleResource;
use Filament\Resources\Pages\EditRecord;

class EditPaymentSchedule extends EditRecord
{
    protected static string $resource = PaymentScheduleResource::class;
}
