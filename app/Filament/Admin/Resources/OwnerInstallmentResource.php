<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\OwnerInstallmentResource\Pages;
use App\Models\OwnerInstallment;
use App\Services\Payment\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class OwnerInstallmentResource extends Resource
{
    protected static ?string $model = OwnerInstallment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_owner_id')->relationship('carOwner', 'first_name')->searchable()->required(),
                Select::make('car_id')->relationship('car', 'registration_number')->searchable()->required(),
                DatePicker::make('period_month')->required(),
                DatePicker::make('due_date')->required(),
                TextInput::make('amount_due')->numeric()->required()->prefix('DZD'),
                Select::make('status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'overdue' => 'Overdue', 'waived' => 'Waived'])->required(),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('carOwner.first_name')->label('Owner'),
                TextColumn::make('car.registration_number'),
                TextColumn::make('period_month')->date()->sortable(),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('amount_due')->money('DZD')->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Pending', 'paid' => 'Paid', 'overdue' => 'Overdue', 'waived' => 'Waived']),
            ])
            ->actions([
                // Accrual is what makes a third-party car's P&L honest: the rent is
                // stamped with car_id, so the car reads revenue minus owner rent
                // minus running costs. Without it the car looks pure profit.
                Action::make('accrue')
                    ->label(__('owner_installments.actions.accrue'))
                    ->icon('heroicon-o-book-open')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription(__('owner_installments.actions.accrue_description'))
                    ->action(function (OwnerInstallment $record, PaymentService $payments): void {
                        $payments->accrueOwnerInstallment($record, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title(__('owner_installments.notifications.accrued'))
                            ->send();
                    })
                    ->visible(fn (OwnerInstallment $record): bool => ! $record->isPostedToLedger()),

                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('due_date', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOwnerInstallments::route('/'),
            'create' => Pages\CreateOwnerInstallment::route('/create'),
            'edit' => Pages\EditOwnerInstallment::route('/{record}/edit'),
        ];
    }
}
