<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\AgreementModel;
use App\Enums\AgreementStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AgreementsRelationManager extends RelationManager
{
    protected static string $relationship = 'agreements';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_owner_id')
                    ->relationship('carOwner', 'first_name')
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
                TextColumn::make('carOwner.first_name')
                    ->sortable()
                    ->searchable()
                    ->label('Owner'),
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
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
