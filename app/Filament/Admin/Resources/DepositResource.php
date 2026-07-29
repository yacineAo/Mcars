<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\DeductionReason;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\DepositResource\Pages;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Services\Payment\DepositService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
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

class DepositResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Deposit::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lock-closed';

    protected static string|UnitEnum|null $navigationGroup = 'Payments';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('reports.view_financials') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('booking_id')->relationship('booking', 'reference')->searchable()->required(),
                Select::make('customer_id')->relationship('customer', 'first_name')->searchable()->required(),
                TextInput::make('amount')->numeric()->required()->prefix('DZD'),
                // Every method the ledger can post, from the enum — the previous
                // hardcoded four silently omitted CCP and cheque.
                Select::make('method')->options(PaymentMethod::options())->required(),
                // Status is owned by DepositService: Hold, Deduct and Refund move
                // it. Editing it by hand would desynchronise it from the ledger,
                // which is what actually determines the deposit's real state.
                Select::make('status')
                    ->options(DepositStatus::options())
                    ->default(DepositStatus::Held->value)
                    ->disabled()
                    ->dehydrated()
                    ->helperText(__('deposits.fields.status_help')),
                DateTimePicker::make('held_at')->required(),
                Textarea::make('notes')->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.reference'),
                TextColumn::make('customer.first_name')->label('Customer'),
                TextColumn::make('amount')->money('DZD')->sortable(),
                TextColumn::make('method'),
                TextColumn::make('status')->badge(),
                TextColumn::make('held_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(DepositStatus::options()),
            ])
            // A deposit is a liability, never revenue. Each of these posts the
            // matching entry: holding credits Security Deposits Held, a deduction
            // converts part of it to revenue, a refund clears the liability.
            ->actions([
                Action::make('hold')
                    ->label(__('deposits.actions.hold'))
                    ->icon('heroicon-o-lock-closed')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (Deposit $record, DepositService $deposits): void {
                        $deposits->hold($record, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title(__('deposits.notifications.held'))
                            ->send();
                    })
                    ->visible(fn (Deposit $record): bool => ! $record->isPostedToLedger()),

                Action::make('deduct')
                    ->label(__('deposits.actions.deduct'))
                    ->icon('heroicon-o-scissors')
                    ->color('warning')
                    ->modalDescription(__('deposits.actions.deduct_description'))
                    ->form([
                        Select::make('reason')
                            ->label(__('deposits.fields.reason'))
                            ->options(DeductionReason::options())
                            ->required(),
                        TextInput::make('amount')
                            ->label(__('deposits.fields.amount'))
                            ->numeric()
                            ->prefix('DZD')
                            ->minValue(0.01)
                            ->required(),
                        Textarea::make('description')
                            ->label(__('deposits.fields.description'))
                            ->maxLength(500),
                    ])
                    ->action(function (Deposit $record, array $data, DepositService $deposits): void {
                        $deduction = new DepositDeduction([
                            'deposit_id' => $record->id,
                            'reason' => $data['reason'],
                            'amount' => $data['amount'],
                            'description' => $data['description'] ?? null,
                            'created_by_id' => Auth::id(),
                        ]);

                        // The service refuses a deduction that would exceed the
                        // deposit, so the operator sees why rather than creating a
                        // negative balance.
                        $deposits->deduct($record, $deduction, (int) Auth::id());

                        Notification::make()
                            ->success()
                            ->title(__('deposits.notifications.deducted'))
                            ->send();
                    })
                    ->visible(fn (Deposit $record): bool => $record->isPostedToLedger()
                        && $record->status->is(DepositStatus::Held, DepositStatus::PartiallyRefunded)),

                Action::make('refund')
                    ->label(__('deposits.actions.refund'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->modalDescription(__('deposits.actions.refund_description'))
                    ->form([
                        TextInput::make('amount')
                            ->label(__('deposits.fields.refund_amount'))
                            ->numeric()
                            ->prefix('DZD')
                            ->helperText(__('deposits.fields.refund_help')),
                    ])
                    ->action(function (Deposit $record, array $data, DepositService $deposits): void {
                        $deposits->refund(
                            $record,
                            ($data['amount'] ?? null) !== null ? (string) $data['amount'] : null,
                            (int) Auth::id(),
                        );

                        Notification::make()
                            ->success()
                            ->title(__('deposits.notifications.refunded'))
                            ->send();
                    })
                    ->visible(fn (Deposit $record): bool => $record->isPostedToLedger()
                        && $record->status->is(DepositStatus::Held, DepositStatus::PartiallyRefunded)),

                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('held_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeposits::route('/'),
            'create' => Pages\CreateDeposit::route('/create'),
            'edit' => Pages\EditDeposit::route('/{record}/edit'),
        ];
    }
}
