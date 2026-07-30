<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnerResource\RelationManagers;

use App\Enums\AgreementModel;
use App\Enums\AgreementStatus;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AgreementsRelationManager extends RelationManager
{
    protected static string $relationship = 'agreements';

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
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->required()
                    ->searchable(),
                Select::make('model')
                    ->options(AgreementModel::options())
                    ->required(),
                Select::make('status')
                    ->options(AgreementStatus::options())
                    ->required(),
                TextInput::make('monthly_rent_amount')
                    ->numeric()
                    ->prefix('DZD'),
                TextInput::make('share_percentage')
                    ->numeric()
                    ->suffix('%'),
                DatePicker::make('start_date')
                    ->required(),
                DatePicker::make('end_date'),
                TextInput::make('payment_day_of_month')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(28),
                TextInput::make('installments_count')
                    ->numeric(),
                DatePicker::make('first_due_date'),
                TextInput::make('grace_days')
                    ->numeric()
                    ->default(0),
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('car.registration_number')
                    ->label('Car')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model')
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable(),
                TextColumn::make('start_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('end_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('monthly_rent_amount')
                    ->money('DZD')
                    ->sortable(),
            ])
            ->filters([])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
