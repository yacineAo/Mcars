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
use Filament\Schemas\Components\Component;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CreateCashSession extends CreateRecord
{
    protected static string $resource = CashSessionResource::class;

    protected function handleRecordCreation(array $data): CashSession
    {
        $account = FinancialAccount::findOrFail($data['financial_account_id']);

        if (! CashSessionResource::userCanReachBranch($account->branch_id)) {
            // The form only offers the operator's own accounts, but the submitted
            // id is trusted by no one: pin the write to the operator's branch
            // server-side (the E64 posting would otherwise land in another
            // branch's ledger).
            throw ValidationException::withMessages([
                'data.financial_account_id' => 'The account must belong to your branch.',
            ]);
        }

        try {
            return app(CashRegisterService::class)
                ->openSession($account, $data['opening_float'] ?? '0', auth()->user());
        } catch (QueryException $e) {
            // The service check and the insert are not atomic: if two opens race,
            // the loser hits the partial unique index (23505 = unique_violation)
            // instead of the RuntimeException below. Show the same refusal, and
            // let anything else surface as the 500 it is.
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            throw ValidationException::withMessages([
                'data.financial_account_id' => 'An open session already exists for this account.',
            ]);
        } catch (RuntimeException $e) {
            // One open session per account is a business invariant enforced by
            // CashRegisterService (and the partial unique index), not a form rule.
            // Surface the service's refusal as a validation message on the field.
            throw ValidationException::withMessages([
                'data.financial_account_id' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<int, Component> */
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
