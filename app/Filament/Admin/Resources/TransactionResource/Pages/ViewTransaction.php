<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TransactionResource\Pages;

use App\Filament\Admin\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\Accounting\AccountingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reverse')
                ->label('Reverse Transaction')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Textarea::make('reason')
                        ->label('Reason for reversal')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data, AccountingService $accounting): void {
                    /** @var Transaction $record */
                    $record = $this->getRecord();

                    if ($record->is_reversal) {
                        Notification::make()
                            ->danger()
                            ->title('Cannot reverse a reversal')
                            ->send();

                        return;
                    }

                    $accounting->reverse($record, $data['reason'], auth()->user());

                    Notification::make()
                        ->success()
                        ->title('Transaction reversed')
                        ->body('A reversal transaction has been posted.')
                        ->send();
                })
                ->visible(fn (Transaction $record): bool => ! $record->is_reversal && auth()->user()?->can('reverse_transaction')),
        ];
    }
}
