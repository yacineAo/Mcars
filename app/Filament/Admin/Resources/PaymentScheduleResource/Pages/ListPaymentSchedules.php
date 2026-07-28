<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentScheduleResource\Pages;

use App\Filament\Admin\Resources\PaymentScheduleResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentSchedules extends ListRecords
{
    protected static string $resource = PaymentScheduleResource::class;
}
