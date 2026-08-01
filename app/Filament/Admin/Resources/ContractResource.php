<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ConditionReportType;
use App\Enums\ContractStatus;
use App\Enums\InsuranceType;
use App\Enums\SignerRole;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Models\Booking;
use App\Models\ConditionReport;
use App\Models\Contract;
use App\Models\Customer;
use App\Services\Booking\ContractService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
use RuntimeException;
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
                Action::make('render_pdf')
                    ->label(__('contracts.actions.render_pdf'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(function (Contract $record): void {
                        app(ContractService::class)->renderPdf($record);

                        Notification::make()->success()->title(__('contracts.notifications.pdf_generated'))->send();
                    })
                    ->visible(fn (Contract $record): bool => static::canOperate() && ! $record->status->is(ContractStatus::Draft)),

                Action::make('send')
                    ->label(__('contracts.actions.send'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (Contract $record): void {
                        app(ContractService::class)->send($record);

                        Notification::make()->success()->title(__('contracts.notifications.sent'))->send();
                    })
                    ->visible(fn (Contract $record): bool => static::canOperate()
                        && $record->content_snapshot !== null
                        && $record->status->is(ContractStatus::Draft, ContractStatus::AwaitingSignature)),

                Action::make('sign')
                    ->label(__('contracts.actions.sign'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading(__('contracts.actions.sign_heading'))
                    ->modalDescription(__('contracts.actions.sign_description'))
                    ->form([
                        Select::make('signer_role')
                            ->label(__('contracts.fields.signer_role'))
                            ->options(SignerRole::options())
                            ->required(),
                        TextInput::make('signer_name')
                            ->label(__('contracts.fields.signer_name'))
                            ->required(),
                    ])
                    ->action(function (Contract $record, array $data): void {
                        try {
                            app(ContractService::class)->markSigned(
                                $record,
                                SignerRole::from($data['signer_role']),
                                $data['signer_name'],
                                Auth::user(),
                            );
                        } catch (RuntimeException $e) {
                            // A concurrent desk may have signed in the same moment —
                            // show the service's refusal rather than a 500.
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('contracts.notifications.signed'))->send();
                    })
                    ->visible(fn (Contract $record): bool => static::canSign()
                        && $record->status->is(ContractStatus::Draft, ContractStatus::AwaitingSignature)),

                Action::make('close')
                    ->label(__('contracts.actions.close'))
                    ->icon('heroicon-o-check-circle')
                    ->color('warning')
                    ->form([
                        Select::make('checkin_report')
                            ->label(__('contracts.fields.checkin_report'))
                            ->options(fn (Contract $record): array => ConditionReport::query()
                                ->where('booking_id', $record->booking_id)
                                ->where('type', ConditionReportType::Checkin)
                                ->get()
                                ->mapWithKeys(fn (ConditionReport $report) => [
                                    $report->id => $report->performed_at?->format('Y-m-d H:i'),
                                ])
                                ->toArray())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Contract $record, array $data): void {
                        try {
                            $report = ConditionReport::findOrFail($data['checkin_report']);

                            app(ContractService::class)->close($record, $report, Auth::user());
                        } catch (RuntimeException $e) {
                            // The report/contract pairing is enforced in the service —
                            // surface its refusal instead of a 500.
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('contracts.notifications.closed'))->send();
                    })
                    ->visible(fn (Contract $record): bool => static::canOperate()
                        && $record->status->is(ContractStatus::Active, ContractStatus::Signed)),

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
