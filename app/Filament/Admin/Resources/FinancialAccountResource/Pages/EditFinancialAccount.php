<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FinancialAccountResource\Pages;

use App\Filament\Admin\Resources\FinancialAccountResource;
use App\Models\FinancialAccount;
use App\Services\FinancialAccountService;
use DomainException;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditFinancialAccount extends EditRecord
{
    protected static string $resource = FinancialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Turning the flag off is a plain field edit; turning it on goes through
     * the service so every other row is demoted in the same transaction.
     * `is_active` is synced onto the in-memory record first so activating and
     * defaulting an account in the same submit does not trip the service's
     * "inactive accounts can't be default" guard against the stale value.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->record;
        assert($record instanceof FinancialAccount);

        if (($data['is_default_for_cash'] ?? false) && ! $record->is_default_for_cash) {
            $record->is_active = (bool) ($data['is_active'] ?? $record->is_active);

            try {
                app(FinancialAccountService::class)->makeDefaultForCash($record, Auth::user());
            } catch (DomainException $e) {
                throw ValidationException::withMessages([
                    'data.is_default_for_cash' => $e->getMessage(),
                ]);
            }
        }

        return $data;
    }
}
