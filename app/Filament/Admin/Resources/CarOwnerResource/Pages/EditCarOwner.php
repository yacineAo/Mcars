<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnerResource\Pages;

use App\Filament\Admin\Resources\CarOwnerResource;
use App\Filament\Admin\Resources\CarOwnerResource\RelationManagers\AgreementsRelationManager;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationGroup;

class EditCarOwner extends EditRecord
{
    protected static string $resource = CarOwnerResource::class;

    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Agreements'), [
                AgreementsRelationManager::class,
            ]),
        ];
    }
}
