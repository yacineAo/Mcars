<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\FinancialAccount;
use App\Models\MaintenanceLog;
use App\Services\Fleet\CompleteMaintenanceService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The workshop record for one car. Completion goes through CompleteMaintenanceService — the
 * same path as MaintenanceLogResource — so the E41 posting happens from either entry point.
 * `status`, the cost fields and `total_cost` are deliberately not on the form: setting
 * status to Completed by hand would record the work and skip the money.
 */
class MaintenanceLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceLogs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Service history');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('fleet.view') ?? false;
    }

    protected function canCreate(): bool
    {
        return Auth::user()?->can('fleet.manage_maintenance') ?? false;
    }

    protected function canEdit(Model $record): bool
    {
        return Auth::user()?->can('fleet.manage_maintenance') ?? false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('vendor_id')
                    ->relationship('vendor', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                    ->searchable()
                    ->nullable(),
                Select::make('type')
                    ->options(MaintenanceType::options())
                    ->required(),
                DatePicker::make('scheduled_for'),
                TextInput::make('odometer_at_service')
                    ->numeric()
                    ->minValue(0),
                Textarea::make('description')
                    ->maxLength(65535)
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('vendor'))
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('scheduled_for')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('completed_at')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('odometer_at_service')
                    ->label('At')
                    ->suffix(' km')
                    ->placeholder('—'),
                TextColumn::make('vendor.name')
                    ->label('Garage')
                    ->searchable()
                    ->placeholder('—'),
                // What the repair cost is money, so it is gated on the permission.
                TextColumn::make('total_cost')
                    ->money('DZD')
                    ->sortable()
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(MaintenanceStatus::options()),
                SelectFilter::make('type')
                    ->options(MaintenanceType::options()),
            ])
            ->defaultSort('scheduled_for', 'desc')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('complete_service')
                    ->label(__('Complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (MaintenanceLog $record): bool => Auth::user()?->can('fleet.manage_maintenance')
                        && ! $record->status->is(MaintenanceStatus::Completed, MaintenanceStatus::Cancelled))
                    ->form([
                        DatePicker::make('completed_at')
                            ->default(now())
                            ->required(),
                        TextInput::make('odometer_at_service')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('cost_parts')
                            ->numeric()
                            ->prefix('DZD')
                            ->default(0),
                        TextInput::make('cost_labour')
                            ->numeric()
                            ->prefix('DZD')
                            ->default(0),
                        TextInput::make('invoice_number')
                            ->maxLength(255),
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
                            ->send();
                    }),

                EditAction::make()
                    ->visible(fn (MaintenanceLog $record): bool => ! $record->status->is(MaintenanceStatus::Completed)),
            ])
            // No delete: a completed log has an E41 row against it, and the ledger is
            // append-only — deleting the log would orphan the posting that explains it.
            ->toolbarActions([]);
    }
}
