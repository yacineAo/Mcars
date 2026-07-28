<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CashSessionResource\Pages;

use App\Filament\Admin\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Services\CashRegisterService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCashSession extends EditRecord
{
    protected static string $resource = CashSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('close')
                ->label('Close Session')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->requiresConfirmation()
                ->form([
                    TextInput::make('counted_amount')
                        ->label('Counted Amount')
                        ->numeric()
                        ->prefix('DZD')
                        ->required(),
                    \Filament\Forms\Components\Textarea::make('notes'),
                ])
                ->action(function (CashSession $record, array $data, CashRegisterService $service): void {
                    $service->closeSession($record, (string) $data['counted_amount'], auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Session closed')
                        ->body('Variance has been posted to the ledger.')
                        ->send();
                })
                ->visible(fn (CashSession $record): bool => $record->status === \App\Enums\CashSessionStatus::Open),

            \Filament\Actions\DeleteAction::make(),
        ];
    }
}
