<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\RelationManagers;

use App\Enums\CarDocumentType;
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

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

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
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
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
