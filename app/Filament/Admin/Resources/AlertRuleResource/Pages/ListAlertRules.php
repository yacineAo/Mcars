<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AlertRuleResource\Pages;

use App\Filament\Admin\Resources\AlertRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlertRules extends ListRecords
{
    protected static string $resource = AlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
