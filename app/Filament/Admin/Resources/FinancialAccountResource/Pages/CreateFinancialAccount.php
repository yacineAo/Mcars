<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FinancialAccountResource\Pages;

use App\Filament\Admin\Resources\FinancialAccountResource;
use App\Models\FinancialAccount;
use Filament\Resources\Pages\CreateRecord;

class CreateFinancialAccount extends CreateRecord
{
    protected static string $resource = FinancialAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['is_default_for_cash'] ?? false)) {
            FinancialAccount::where('is_default_for_cash', true)->update(['is_default_for_cash' => false]);
        }

        return $data;
    }
}
