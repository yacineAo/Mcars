<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExpenseCategoryResource\Pages;

use App\Filament\Admin\Resources\ExpenseCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditExpenseCategory extends EditRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
