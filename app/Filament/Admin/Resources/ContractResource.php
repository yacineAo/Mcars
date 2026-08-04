<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ContractStatus;
use App\Enums\InsuranceType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\Customer;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Rental contracts for bookings — the document, its signature lifecycle and its money.
 *
 * The contract is a *snapshot*: its `content_snapshot` embeds the customer, the car and
 * the prices as they stood when it was generated, so the document survives later edits
 * to the booking and to the template (ADR-005). Everything that changes a contract
 * lives in ContractService: generating it (which numbers it from the per-branch
 * sequence), rendering the PDF, sending it and closing it from a check-in report.
 *
 * The status machine: Draft → AwaitingSignature → Signed → Closed, with Amended and
 * Cancelled. Signing freezes the terms — once `isLocked()`, the form here disables
 * every term field. "Sign" is a deliberate, separate capability (`contracts.sign`):
 * the accountant reads contracts but never freezes a price into a document, and the
 * desk staff sign customers in.
 */
class ContractResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Contract::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('bookings.view') ?? false;
    }

    public static function canOperate(): bool
    {
        return Auth::user()?->can('bookings.operate') ?? false;
    }

    /**
     * Signing freezes a price-bearing document, so it has its own permission rather
     * than riding on `bookings.operate`: an accountant with read access must never be
     * the one who locks a price in.
     */
    public static function canSign(): bool
    {
        return Auth::user()?->can('contracts.sign') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canOperate();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canOperate();
    }

    /**
     * Only a draft can be deleted.
     *
     * A signed contract is a legal document: its terms were frozen the moment the
     * signature went on, and removing it would destroy the record of what the customer
     * accepted. The catalogue guard mirrors `bookings.manage` — deleting contracts is
     * the manager's call.
     */
    public static function canDelete(Model $record): bool
    {
        if (! ($record instanceof Contract)) {
            return false;
        }

        return $record->status->is(ContractStatus::Draft)
            && (Auth::user()?->can('bookings.manage') ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('booking_id')
                    ->label(__('Booking'))
                    // Only bookings without a contract yet — ContractService throws on
                    // a booking that already has one. The booking pins the contract's
                    // snapshot, so it is immutable once created: changing it here would
                    // desync the document from the booking it quotes.
                    ->options(fn () => Booking::query()
                        ->whereDoesntHave('contract')
                        ->get()
                        ->mapWithKeys(fn (Booking $booking) => [$booking->id => $booking->reference])
                        ->toArray())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->disabled(fn (?Contract $record): bool => $record !== null),
                Select::make('contract_template_id')
                    ->label(__('Template'))
                    ->relationship('template', 'name')
                    ->nullable()
                    ->disabled(fn (?Contract $record): bool => $record?->isLocked() ?? false),
                Select::make('insurance_type')
                    ->label(__('Insurance type'))
                    ->options(InsuranceType::options())
                    ->nullable()
                    ->disabled(fn (?Contract $record): bool => $record?->isLocked() ?? false),
                TextInput::make('franchise_amount')
                    ->label(__('Franchise amount'))
                    ->numeric()
                    ->default(0)
                    ->prefix('DZD')
                    ->disabled(fn (?Contract $record): bool => $record?->isLocked() ?? false),
                Textarea::make('closing_notes')
                    ->label(__('Closing notes'))
                    // Only meaningful on an existing contract — on create the notes
                    // are written by the close action from the check-in report.
                    ->hidden(fn (?Contract $record): bool => $record === null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract_number')->label(__('Number')),
                TextColumn::make('booking.reference')->label(__('Booking')),
                TextColumn::make('customer')
                    ->label(__('Customer'))
                    ->state(fn (Contract $record): string => $record->customer?->displayName() ?? '—'),
                TextColumn::make('car.registration_number')->label(__('Car')),
                TextColumn::make('status')->label(__('Status'))->badge(),
                IconColumn::make('has_damages')->label(__('Damages'))->boolean(),
                TextColumn::make('generated_at')->label(__('Generated'))->dateTime(),
                TextColumn::make('signed_at')->label(__('Signed'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ContractStatus::options())
                    ->default(ContractStatus::AwaitingSignature->value),
                SelectFilter::make('customer_id')
                    ->label(__('Customer'))
                    ->options(fn () => Customer::query()
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Customer $customer) => [
                            $customer->id => $customer->displayName(),
                        ])
                        ->toArray())
                    ->searchable(),
                Filter::make('generated_between')
                    ->label(__('Generated'))
                    ->schema([
                        DatePicker::make('generated_from')->label(__('From')),
                        DatePicker::make('generated_until')->label(__('Until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $from = $data['generated_from'] ?? null;
                        $until = $data['generated_until'] ?? null;

                        // Half-open bounds in the app's Africa/Algiers timezone: a
                        // picker date selects the whole local day, and the Postgres
                        // session may run on UTC.
                        return $query
                            ->when($from, fn (Builder $q): Builder => $q->where('generated_at', '>=', CarbonImmutable::parse($from)->startOfDay()))
                            ->when($until, fn (Builder $q): Builder => $q->where('generated_at', '<', CarbonImmutable::parse($until)->addDay()->startOfDay()));
                    }),
            ])
            ->recordActions([
                // The lifecycle actions (render_pdf, download_pdf, send, sign, close) live on
                // the View page's header instead of here — seven icons per row made the list
                // unusable, and the record's own status and document are visible right next to
                // them there. See ViewContract::getHeaderActions().
                ViewAction::make(),

                EditAction::make()
                    ->visible(fn (Contract $record): bool => static::canEdit($record)),

                DeleteAction::make()
                    ->visible(fn (Contract $record): bool => static::canDelete($record)),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            ContractResource\RelationManagers\SignaturesRelationManager::class,
            ContractResource\RelationManagers\ChildContractsRelationManager::class,
            ContractResource\RelationManagers\DepositsRelationManager::class,
            ContractResource\RelationManagers\FinesRelationManager::class,
            ContractResource\RelationManagers\ConditionReportsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ContractResource\Pages\ListContracts::route('/'),
            'create' => ContractResource\Pages\CreateContract::route('/create'),
            'view' => ContractResource\Pages\ViewContract::route('/{record}'),
            'edit' => ContractResource\Pages\EditContract::route('/{record}/edit'),
        ];
    }
}
