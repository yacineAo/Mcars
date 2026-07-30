<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FinancialAccountResource\Pages;

use App\Filament\Admin\Resources\FinancialAccountResource;
use App\Models\FinancialAccount;
use Filament\Resources\Pages\EditRecord;

class EditFinancialAccount extends EditRecord
{
    protected static string $resource = FinancialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['is_default_for_cash'] ?? false)) {
            $record = $this->record;
            assert($record instanceof FinancialAccount);

            FinancialAccount::where('is_default_for_cash', true)
                ->where('id', '!=', $record->id)
                ->update(['is_default_for_cash' => false]);
        }

        return $data;
    }
}
