<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingResource\RelationManagers;

use App\Filament\Admin\Resources\BookingResource;
use App\Models\Booking;
use App\Services\Booking\BookingService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Extras billed on this booking — the office adjusts these, so it is editable.
 *
 * Editable only until the rental is invoiced: `extras_total` is posted to the ledger at
 * checkout (matrix E04), so adding a line afterwards would charge the customer nothing
 * while showing on the booking. Extras discovered after handover belong on the closeout.
 */
class ExtrasRelationManager extends RelationManager
{
    protected static string $relationship = 'extras';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return BookingResource::canAccess();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Select::make('extra_id')
                ->label(__('Extra'))
                ->relationship('extra', 'name')
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('quantity')->label(__('Quantity'))->numeric()->minValue(1)->default(1)->required(),
            TextInput::make('unit_price')->label(__('Unit price'))->numeric()->prefix('DZD')->required(),
            // Never typed: the line total is quantity × unit price, and the operator
            // used to be able to enter all three with nothing reconciling them — while
            // `total` is the figure that reaches `extras_total` and E04. Shown so the
            // office can see what it is agreeing to; computed in BookingService.
            TextInput::make('total')
                ->label(__('Total'))
                ->numeric()
                ->prefix('DZD')
                ->disabled()
                ->dehydrated()
                ->helperText(__('Computed from quantity × unit price.')),
        ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Extras');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('extra.name')->label(__('Extra')),
                TextColumn::make('quantity')->label(__('Quantity')),
                TextColumn::make('unit_price')->label(__('Unit price'))->money('DZD'),
                TextColumn::make('total')->label(__('Total'))->money('DZD'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('extra'))
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->canMutate())
                    ->mutateDataUsing(fn (array $data): array => app(BookingService::class)->priceExtraLine($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canMutate())
                    ->mutateDataUsing(fn (array $data): array => app(BookingService::class)->priceExtraLine($data)),
                DeleteAction::make()->visible(fn (): bool => $this->canMutate()),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return ! $this->canMutate();
    }

    protected function canCreate(): bool
    {
        return $this->canMutate();
    }

    protected function canEdit(Model $record): bool
    {
        return $this->canMutate();
    }

    protected function canDelete(Model $record): bool
    {
        return $this->canMutate();
    }

    /** Writable while the booking is still un-invoiced, and only by an operator. */
    private function canMutate(): bool
    {
        $booking = $this->getOwnerRecord();

        return $booking instanceof Booking
            && ! $booking->hasStarted()
            && BookingResource::canOperate();
    }
}
