<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\CreateMaintenanceLog;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\EditMaintenanceLog;
use App\Filament\Admin\Resources\MaintenanceLogResource\Pages\ListMaintenanceLogs;
use App\Models\FinancialAccount;
use App\Models\MaintenanceLog;
use App\Services\Fleet\CompleteMaintenanceService;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
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
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;

class MaintenanceLogResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = MaintenanceLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->required()
                    ->searchable(),
                Select::make('vendor_id')
                    ->relationship('vendor', 'name')
                    ->searchable()
                    ->nullable(),
                Select::make('type')
                    ->options(MaintenanceType::options())
                    ->required(),
                Select::make('status')
                    ->options(MaintenanceStatus::options())
                    ->required(),
                DatePicker::make('scheduled_for'),
                DatePicker::make('started_at'),
                DatePicker::make('completed_at'),
                TextInput::make('odometer_at_service')
                    ->numeric(),
                TextInput::make('cost_parts')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('cost_labour')
                    ->numeric()
                    ->prefix('DZD'),
                // Derived from parts + labour by CompleteMaintenanceService. Hand-editable
                // it could read 100 against parts 5,000 and labour 3,000 — and it is the
                // figure E41 posts, so a disagreement would land in the ledger.
                TextInput::make('total_cost')
                    ->numeric()
                    ->prefix('DZD')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(__('Parts + labour, set when the service is completed.')),
                TextInput::make('invoice_number')
                    ->maxLength(255),
                DatePicker::make('next_due_date'),
                TextInput::make('next_due_odometer')
                    ->numeric(),
                Textarea::make('description')
                    ->maxLength(65535),
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('car.registration_number')
                    ->sortable()
                    ->searchable()
                    ->label('Car'),
                TextColumn::make('type')
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable(),
                TextColumn::make('scheduled_for')
                    ->date()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->date()
                    ->sortable(),
                TextColumn::make('total_cost')
                    ->money('DZD')
                    ->sortable(),
                TextColumn::make('vendor.name')
                    ->searchable(),
            ])
            ->filters([])
            ->actions([
                Action::make('start_service')
                    ->label('Start Service')
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (MaintenanceLog $record): void {
                        $record->update([
                            'status' => MaintenanceStatus::InProgress,
                            'started_at' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Maintenance service started')
                            ->send();
                    })
                    ->visible(fn (MaintenanceLog $record): bool => $record->status === MaintenanceStatus::Scheduled),

                Action::make('complete_service')
                    ->label('Complete Service')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->form([
                        DatePicker::make('completed_at')->default(now())->required(),
                        TextInput::make('odometer_at_service')->numeric()->minValue(0)->required(),
                        TextInput::make('cost_parts')->numeric()->prefix('DZD')->default(0),
                        TextInput::make('cost_labour')->numeric()->prefix('DZD')->default(0),
                        TextInput::make('invoice_number')->maxLength(255),
                        // Which side of E41 the credit lands on. Choosing an account pays
                        // the garage now (Cr 1010/1020); leaving it empty puts the bill on
                        // credit against 2210 AP–Suppliers.
                        Select::make('financial_account_id')
                            ->label(__('Paid from'))
                            ->helperText(__('Leave empty to record the bill as owed to the supplier.'))
                            ->options(fn (): array => FinancialAccount::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                    ])
                    ->action(function (MaintenanceLog $record, array $data): void {
                        $accountId = $data['financial_account_id'] ?? null;

                        try {
                            app(CompleteMaintenanceService::class)->complete(
                                log: $record,
                                completedAt: CarbonImmutable::parse((string) $data['completed_at']),
                                odometerAtService: (int) $data['odometer_at_service'],
                                costParts: Money::of($data['cost_parts'] ?? '0'),
                                costLabour: Money::of($data['cost_labour'] ?? '0'),
                                invoiceNumber: $data['invoice_number'] ?? null,
                                account: $accountId !== null ? FinancialAccount::find($accountId) : null,
                                userId: (int) Auth::id(),
                            );
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('Service not completed'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Maintenance service completed'))
                            ->body(__('The cost was posted to the ledger against this car.'))
                            ->send();
                    })
                    ->visible(fn (MaintenanceLog $record): bool => $record->status !== MaintenanceStatus::Completed && $record->status !== MaintenanceStatus::Cancelled),

                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceLogs::route('/'),
            'create' => CreateMaintenanceLog::route('/create'),
            'edit' => EditMaintenanceLog::route('/{record}/edit'),
        ];
    }
}
