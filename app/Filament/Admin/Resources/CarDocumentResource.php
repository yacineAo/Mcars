<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\CarDocumentType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\CarDocumentResource\Pages\CreateCarDocument;
use App\Filament\Admin\Resources\CarDocumentResource\Pages\EditCarDocument;
use App\Filament\Admin\Resources\CarDocumentResource\Pages\ListCarDocuments;
use App\Models\CarDocument;
use App\Models\FinancialAccount;
use App\Services\Fleet\RecordDocumentRenewalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Throwable;
use UnitEnum;

class CarDocumentResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = CarDocument::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('fleet.view') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        /** @var CarDocument $record */
        if ($record->replaced_by_id !== null) {
            return false;
        }

        return Auth::user()?->can('fleet.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        /** @var CarDocument $record */
        return $record->replaced_by_id === null && (Auth::user()?->can('fleet.manage') ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->required()
                    ->searchable()
                    ->disabled(fn (?CarDocument $record, string $operation): bool => $operation === 'edit'),
                Select::make('type')
                    ->options(CarDocumentType::options())
                    ->required()
                    ->disabled(fn (?CarDocument $record, string $operation): bool => $operation === 'edit'),
                TextInput::make('number')
                    ->maxLength(255)
                    ->disabled(fn (?CarDocument $record): bool => $record?->replaced_by_id !== null),
                TextInput::make('issuer')
                    ->maxLength(255)
                    ->disabled(fn (?CarDocument $record): bool => $record?->replaced_by_id !== null),
                DatePicker::make('issue_date')
                    ->disabled(fn (?CarDocument $record): bool => $record?->replaced_by_id !== null),
                DatePicker::make('expiry_date')
                    ->required()
                    ->helperText(__('Documents without an expiry date are invisible to the alert system.'))
                    ->disabled(fn (?CarDocument $record): bool => $record?->replaced_by_id !== null),
                TextInput::make('cost')
                    ->numeric()
                    ->prefix('DZD')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->disabled(fn (?CarDocument $record): bool => $record?->replaced_by_id !== null),
                TextInput::make('reminder_days_before')
                    ->numeric()
                    ->default(30)
                    ->helperText(__('Raises the floor under the alert rule lead time, does not replace it.'))
                    ->disabled(fn (?CarDocument $record): bool => $record?->replaced_by_id !== null),
                SpatieMediaLibraryFileUpload::make('document')
                    ->collection('document')
                    ->disk('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(10240)
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->maxLength(65535)
                    ->disabled(fn (?CarDocument $record): bool => $record?->replaced_by_id !== null),
            ]);
    }

    /**
     * Colour logic for the days-remaining column:
     * - `danger` — past or today
     * - `warning` — inside the document's own reminder window
     * - `gray` — comfortably in the future
     */
    private static function daysRemainingColor(CarDocument $record): string
    {
        $days = $record->expiry_date?->diffInDays(now(), false);

        return match (true) {
            $days === null, $days > (int) ($record->reminder_days_before ?? 30) => 'gray',
            $days <= 0 => 'danger',
            default => 'warning',
        };
    }

    /**
     * Selects `posted_to_ledger` once for the table and eager-loads media so the
     * preview-scan action's visibility check does not N+1.
     *
     * @param Builder<CarDocument> $query
     * @return Builder<CarDocument>
     */
    protected static function withLedgerFlag(Builder $query): Builder
    {
        return $query->withPostedToLedger()->with('media');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => self::withLedgerFlag($query))
            ->columns([
                TextColumn::make('car.registration_number')
                    ->label(__('Car'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('number')
                    ->searchable(),
                TextColumn::make('days_remaining')
                    ->label(__('Days remaining'))
                    ->state(fn (CarDocument $record) => $record->expiry_date?->diffInDays(now(), false))
                    ->formatStateUsing(function (?int $state, CarDocument $record): string {
                        if ($state === null) {
                            return '—';
                        }
                        if ($state <= 0) {
                            return __('Expired');
                        }

                        return trans_choice('{1} :count day|[2,*] :count days', $state, ['count' => $state]);
                    })
                    ->color(fn (CarDocument $record): string => self::daysRemainingColor($record)),
                TextColumn::make('issuer'),
                TextColumn::make('issue_date')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('replaced_by_id')
                    ->label(__('Superseded'))
                    ->boolean()
                    ->trueIcon('heroicon-o-archive-box')
                    ->falseIcon('heroicon-o-minus'),
                TextColumn::make('cost')
                    ->money('DZD')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
                IconColumn::make('posted_to_ledger')
                    ->label(__('In ledger'))
                    ->boolean()
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
            ])
            ->filters([
                SelectFilter::make('expiry_window')
                    ->label(__('Expiry window'))
                    ->options([
                        'expired_or_expiring' => __('Expired or expiring'),
                        'expired' => __('Expired'),
                        'expiring_30' => __('Expiring in 30 days'),
                        'expiring_90' => __('Expiring in 90 days'),
                        'all' => __('All'),
                    ])
                    ->default('expired_or_expiring')
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === 'all') {
                            return $query;
                        }

                        return match ($value) {
                            'expired' => $query->whereNotNull('expiry_date')->where('expiry_date', '<', now()->startOfDay()),
                            'expiring_30' => $query
                                ->whereNotNull('expiry_date')
                                ->where('expiry_date', '>=', now()->startOfDay())
                                ->where('expiry_date', '<=', now()->addDays(30)->endOfDay()),
                            'expiring_90' => $query
                                ->whereNotNull('expiry_date')
                                ->where('expiry_date', '>=', now()->startOfDay())
                                ->where('expiry_date', '<=', now()->addDays(90)->endOfDay()),
                            'expired_or_expiring' => $query
                                ->whereNotNull('expiry_date')
                                ->where(function (Builder $q): Builder {
                                    return $q->where('expiry_date', '<', now()->startOfDay())
                                        ->orWhere('expiry_date', '<=', now()->addDays(30)->endOfDay());
                                }),
                            default => $query,
                        };
                    }),
                TernaryFilter::make('replaced_by_id')
                    ->label(__('Status'))
                    ->placeholder(__('All documents'))
                    ->trueLabel(__('Current only'))
                    ->falseLabel(__('Superseded only'))
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('replaced_by_id'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('replaced_by_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('type')
                    ->options(CarDocumentType::options()),
                SelectFilter::make('car_id')
                    ->label(__('Car'))
                    ->relationship('car', 'registration_number')
                    ->searchable(),
                SelectFilter::make('branch_id')
                    ->label(__('Branch'))
                    ->relationship('car.branch', 'name')
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
            ])
            ->defaultSort('expiry_date', 'asc')
            ->recordActions([
                Action::make('renew')
                    ->label(__('Renew Document'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (CarDocument $record): bool => $record->replaced_by_id === null
                        && (Auth::user()?->can('fleet.manage') ?? false))
                    ->form([
                        TextInput::make('number')
                            ->label(__('New Document Number'))
                            ->required(),
                        TextInput::make('issuer')
                            ->maxLength(255),
                        DatePicker::make('issue_date')
                            ->default(now())
                            ->required(),
                        DatePicker::make('expiry_date')
                            ->required(),
                        Select::make('financial_account_id')
                            ->label(__('Paid from'))
                            ->helperText(__('Leave empty to record the bill as owed to the supplier.'))
                            ->options(fn (): array => FinancialAccount::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable(),
                        TextInput::make('cost')
                            ->numeric()
                            ->prefix('DZD')
                            ->helperText(__('Record 0 to skip posting to ledger.')),
                    ])
                    ->action(function (CarDocument $record, array $data): void {
                        $accountId = $data['financial_account_id'] ?? null;

                        try {
                            app(RecordDocumentRenewalService::class)->renew(
                                $record,
                                $data,
                                $accountId !== null ? FinancialAccount::find($accountId) : null,
                                (int) Auth::id(),
                            );
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('Renewal failed'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Document renewed successfully'))
                            ->send();
                    }),

                Action::make('preview_scan')
                    ->label(__('Preview scan'))
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn (CarDocument $record): bool => $record->getFirstMedia('document') !== null
                        && (Auth::user()?->can('fleet.view') ?? false))
                    ->url(fn (CarDocument $record): string => URL::temporarySignedRoute(
                        'media.car-documents.download',
                        now()->addMinutes(5),
                        ['carDocument' => $record->id],
                    ))
                    ->openUrlInNewTab(),

                EditAction::make()
                    ->visible(fn (CarDocument $record): bool => $record->replaced_by_id === null
                        && (Auth::user()?->can('fleet.manage') ?? false)),

                DeleteAction::make()
                    ->visible(fn (CarDocument $record): bool => $record->replaced_by_id === null
                        && (Auth::user()?->can('fleet.manage') ?? false)),
            ])
            // No bulk actions. A single delete is the correct granularity for evidence
            // that a car was insured on a given day; bulk-deleting them open a risk that
            // the replaced_by_id chain is broken.
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarDocuments::route('/'),
            'create' => CreateCarDocument::route('/create'),
            'edit' => EditCarDocument::route('/{record}/edit'),
        ];
    }
}
