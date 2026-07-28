<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AlertRuleResource\Pages;

use App\Filament\Admin\Resources\AlertRuleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlertRule extends CreateRecord
{
    protected static string $resource = AlertRuleResource::class;
}
