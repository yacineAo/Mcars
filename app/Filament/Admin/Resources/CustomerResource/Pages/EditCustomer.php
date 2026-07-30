<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CustomerResource\Pages;

use App\Filament\Admin\Resources\CustomerResource;
use App\Filament\Admin\Resources\CustomerResource\RelationManagers\DocumentsRelationManager;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\RelationManagers\RelationGroup;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Documents'), [
                DocumentsRelationManager::class,
            ]),
        ];
    }
}
