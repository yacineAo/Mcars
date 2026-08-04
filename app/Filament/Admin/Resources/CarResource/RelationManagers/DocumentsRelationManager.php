<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\CarDocumentType;
use App\Models\CarDocument;
use App\Models\FinancialAccount;
use App\Services\Fleet\RecordDocumentRenewalService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Documents');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('fleet.view') ?? false;
    }

    protected function canCreate(): bool
    {
        return Auth::user()?->can('fleet.manage') ?? false;
    }

    protected function canEdit(Model $record): bool
    {
        return Auth::user()?->can('fleet.manage') ?? false;
    }

    protected function canDelete(Model $record): bool
    {
        return Auth::user()?->can('fleet.manage') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('type')
                    ->options(CarDocumentType::options())
                    ->required(),
                TextInput::make('number')
                    ->maxLength(255),
                TextInput::make('issuer')
                    ->maxLength(255),
                DatePicker::make('issue_date'),
                DatePicker::make('expiry_date'),
                TextInput::make('cost')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('reminder_days_before')
                    ->numeric()
                    ->default(30),
                // ADV-02: the scan itself. CarDocument pins this collection to the private
                // disk (ADR-009), so the file is never reachable by URL guess.
                SpatieMediaLibraryFileUpload::make('document')
                    ->collection('document')
                    ->disk('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(10240)
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    /**
     * Selects `posted_to_ledger` once for the page. Asking the service per row was two
     * `select exists` queries per document — once for the column, once for the action's
     * visibility.
     *
     * @param Builder<CarDocument> $query
     * @return Builder<CarDocument>
     */
    protected function withLedgerFlag(Builder $query): Builder
    {
        return $query->withPostedToLedger();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing($this->withLedgerFlag(...))
            ->columns([
                TextColumn::make('type')
                    ->sortable(),
                TextColumn::make('number')
                    ->searchable(),
                TextColumn::make('issuer')
                    ->searchable(),
                TextColumn::make('issue_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('expiry_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('cost')
                    ->money('DZD')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
                IconColumn::make('posted_to_ledger')
                    ->label('In ledger')
                    ->boolean()
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
            ])
            ->filters([])
            ->defaultSort('expiry_date', 'desc')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                // E42. Explicit rather than automatic on save: documents are also recorded
                // with no cost, and back-filled years after they were paid for — posting on
                // every save would invent expenses that per-car profitability would believe.
                Action::make('post_renewal_cost')
                    ->label(__('Post cost to ledger'))
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('This writes an expense against this car. The ledger is append-only, so it can only be undone by a reversal.'))
                    ->visible(fn (CarDocument $record): bool => (Auth::user()?->can('fleet.manage') ?? false)
                        && Money::of($record->cost ?? '0')->isPositive()
                        && app(RecordDocumentRenewalService::class)->isPostable($record->type)
                        && ! $record->getAttribute('posted_to_ledger'))
                    ->form([
                        Select::make('financial_account_id')
                            ->label(__('Paid from'))
                            ->helperText(__('Leave empty to record the bill as owed to the supplier.'))
                            ->options(fn (): array => FinancialAccount::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload(),
                    ])
                    ->action(function (CarDocument $record, array $data): void {
                        $accountId = $data['financial_account_id'] ?? null;

                        try {
                            app(RecordDocumentRenewalService::class)->record(
                                $record,
                                $accountId !== null ? FinancialAccount::find($accountId) : null,
                                (int) Auth::id(),
                            );
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('Not posted'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Renewal cost posted'))
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            // Single delete only. CarDocumentObserver rebuilds the car's expiry mirror
            // columns per row; a bulk delete makes "is this car road-legal" wrong in bulk.
            ->toolbarActions([]);
    }
}
