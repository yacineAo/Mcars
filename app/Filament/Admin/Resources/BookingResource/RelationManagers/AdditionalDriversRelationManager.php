<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingResource\RelationManagers;

use App\Filament\Admin\Resources\BookingResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Extra people insured to drive the car.
 *
 * Editable for the whole life of the booking, unlike the extras: adding a driver
 * mid-rental is a normal request and costs nothing, so there is no ledger row to
 * desynchronise.
 */
class AdditionalDriversRelationManager extends RelationManager
{
    protected static string $relationship = 'additionalDrivers';

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('full_name')->label(__('Full name'))->required(),
            TextInput::make('national_id')->label(__('National id')),
            TextInput::make('driving_license_number')->label(__('Driving license number')),
            DatePicker::make('driving_license_expiry')->label(__('Driving license expiry')),
            TextInput::make('phone')->label(__('Phone'))->tel(),
        ]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Additional Drivers');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label(__('Full name'))->searchable(),
                TextColumn::make('driving_license_number')->label(__('Driving license number')),
                TextColumn::make('driving_license_expiry')
                    ->label(__('Driving license expiry'))
                    ->date()
                    ->placeholder('—')
                    // An expired licence on an insured driver is a live liability.
                    ->color(fn ($state): ?string => $state !== null && $state->isPast() ? 'danger' : null),
                TextColumn::make('phone')->label(__('Phone')),
            ])
            ->headerActions([
                CreateAction::make()->visible(fn (): bool => BookingResource::canOperate()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => BookingResource::canOperate()),
                DeleteAction::make()->visible(fn (): bool => BookingResource::canOperate()),
            ])
            ->bulkActions([]);
    }

    public function isReadOnly(): bool
    {
        return ! BookingResource::canOperate();
    }

    protected function canCreate(): bool
    {
        return BookingResource::canOperate();
    }

    protected function canEdit(Model $record): bool
    {
        return BookingResource::canOperate();
    }

    protected function canDelete(Model $record): bool
    {
        return BookingResource::canOperate();
    }
}
