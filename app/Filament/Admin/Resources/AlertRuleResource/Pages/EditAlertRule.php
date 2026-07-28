<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AlertRuleResource\Pages;

use App\Filament\Admin\Resources\AlertRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAlertRule extends EditRecord
{
    protected static string $resource = AlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
