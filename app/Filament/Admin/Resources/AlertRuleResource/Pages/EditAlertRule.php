<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AlertRuleResource\Pages;

use App\Filament\Admin\Resources\AlertRuleResource;
use App\Models\AlertRule;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAlertRule extends EditRecord
{
    protected static string $resource = AlertRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // Deleting a rule silently ends that alert — for every branch
                // when it is the global rule. Name what stops so the
                // confirmation is a decision, not a formality. (Deactivating is
                // the reversible alternative and is offered on the index.)
                ->modalDescription(fn (AlertRule $record): string => __(
                    'notifications.resources.alert_rule.actions.delete_confirm',
                    ['type' => $record->type->getLabel()],
                )),
        ];
    }
}
