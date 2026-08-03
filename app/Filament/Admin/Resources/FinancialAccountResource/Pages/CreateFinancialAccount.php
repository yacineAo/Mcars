<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FinancialAccountResource\Pages;

use App\Filament\Admin\Resources\FinancialAccountResource;
use App\Models\FinancialAccount;
use App\Services\FinancialAccountService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateFinancialAccount extends CreateRecord
{
    protected static string $resource = FinancialAccountResource::class;

    private bool $wantsDefaultForCash = false;

    /**
     * The new row cannot be promoted to default before it exists — held back
     * to afterCreate() so FinancialAccountService::makeDefaultForCash() has a
     * record to demote the others in favour of.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->wantsDefaultForCash = (bool) ($data['is_default_for_cash'] ?? false);
        $data['is_default_for_cash'] = false;

        return $data;
    }

    protected function afterCreate(): void
    {
        if (! $this->wantsDefaultForCash) {
            return;
        }

        $record = $this->record;
        assert($record instanceof FinancialAccount);

        try {
            app(FinancialAccountService::class)->makeDefaultForCash($record, Auth::user());
        } catch (DomainException $e) {
            // The record already exists by this point, so a ValidationException
            // (which re-renders the form) is not the right shape — the account
            // was created, just not promoted. Tell the user why and move on.
            Notification::make()
                ->danger()
                ->title(__('Account created, but not set as default'))
                ->body($e->getMessage())
                ->send();
        }
    }
}
