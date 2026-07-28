<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CashSessionResource\Pages;

use App\Filament\Admin\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Models\FinancialAccount;
use App\Services\CashRegisterService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCashSession extends CreateRecord
{
    protected static string $resource = CashSessionResource::class;

    protected function handleRecordCreation(array $data): CashSession
    {
        $account = FinancialAccount::findOrFail($data['financial_account_id']);
        $service = app(CashRegisterService::class);

        return $service->openSession($account, $data['opening_float'] ?? '0', auth()->user());
    }

    protected function getFormSchema(): array
    {
        return [
            Select::make('financial_account_id')
                ->label('Cash Account')
                ->options(FinancialAccount::query()->where('is_active', true)->pluck('name', 'id'))
                ->searchable()
                ->required(),
            TextInput::make('opening_float')
                ->numeric()
                ->prefix('DZD')
                ->required()
                ->default(0),
        ];
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->success()
            ->title('Cash session opened')
            ->send();
    }
}
