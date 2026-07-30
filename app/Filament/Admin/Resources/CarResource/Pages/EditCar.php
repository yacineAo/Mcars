<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\Pages;

use App\Filament\Admin\Resources\CarResource;
use App\Filament\Admin\Resources\CarResource\RelationManagers\AgreementsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\MaintenanceLogsRelationManager;
use App\Filament\Admin\Resources\CarResource\RelationManagers\MaintenanceSchedulesRelationManager;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationGroup;

class EditCar extends EditRecord
{
    protected static string $resource = CarResource::class;

    /**
     * The tables that are maintained in place. Read-only history (bookings, contracts,
     * fines, blocks, owner instalments) lives on ViewCar instead — nine flat tabs is too
     * many to scan, so they are grouped the way docs/02-filament-panels.md §Car page does.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Documents'), [
                DocumentsRelationManager::class,
            ]),
            RelationGroup::make(__('Maintenance'), [
                MaintenanceLogsRelationManager::class,
                MaintenanceSchedulesRelationManager::class,
            ]),
            RelationGroup::make(__('Owner'), [
                AgreementsRelationManager::class,
            ]),
        ];
    }
}
