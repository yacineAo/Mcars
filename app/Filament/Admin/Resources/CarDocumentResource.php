<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\CarDocumentType;
use App\Filament\Admin\Resources\CarDocumentResource\Pages\CreateCarDocument;
use App\Filament\Admin\Resources\CarDocumentResource\Pages\EditCarDocument;
use App\Filament\Admin\Resources\CarDocumentResource\Pages\ListCarDocuments;
use App\Models\CarDocument;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CarDocumentResource extends Resource
{
    protected static ?string $model = CarDocument::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('car_id')
                    ->relationship('car', 'registration_number')
                    ->required()
                    ->searchable(),
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('car.registration_number')
                    ->sortable()
                    ->searchable()
                    ->label('Car'),
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
            ->actions([
                Action::make('renew')
                    ->label('Renew Document')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn (CarDocument $record): bool => $record->replaced_by_id === null)
                    ->form([
                        TextInput::make('number')
                            ->label('New Document Number')
                            ->required(),
                        TextInput::make('issuer')
                            ->maxLength(255),
                        DatePicker::make('issue_date')
                            ->default(now())
                            ->required(),
                        DatePicker::make('expiry_date')
                            ->required(),
                        TextInput::make('cost')
                            ->numeric()
                            ->prefix('DZD'),
                    ])
                    ->action(function (CarDocument $record, array $data): void {
                        $newDoc = CarDocument::create([
                            'car_id' => $record->car_id,
                            'type' => $record->type,
                            'number' => $data['number'],
                            'issuer' => $data['issuer'] ?? $record->issuer,
                            'issue_date' => $data['issue_date'],
                            'expiry_date' => $data['expiry_date'],
                            'cost' => $data['cost'] ?? 0,
                            'reminder_days_before' => $record->reminder_days_before,
                        ]);

                        $record->update(['replaced_by_id' => $newDoc->id]);

                        Notification::make()
                            ->success()
                            ->title('Document renewed successfully')
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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
