<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BranchResource\Pages;

use App\Filament\Admin\Resources\BranchResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
