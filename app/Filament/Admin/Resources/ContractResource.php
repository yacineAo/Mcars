<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ContractStatus;
use App\Enums\InsuranceType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Models\Contract;
use App\Services\Booking\ContractService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ContractResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Contract::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('booking_id')
                    ->relationship('booking', 'reference')
                    ->searchable()
                    ->required(),
                Select::make('contract_template_id')
                    ->relationship('template', 'name')
                    ->nullable(),
                Select::make('insurance_type')
                    ->options(InsuranceType::options())
                    ->nullable(),
                TextInput::make('franchise_amount')->numeric()->default(0)->prefix('DZD'),
                Textarea::make('closing_notes'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('contract_number'),
                TextColumn::make('booking.reference'),
                TextColumn::make('customer.first_name')->label('Customer'),
                TextColumn::make('car.registration_number'),
                TextColumn::make('status')
                    ->badge(),
                IconColumn::make('has_damages')->boolean(),
                TextColumn::make('generated_at')->dateTime(),
                TextColumn::make('signed_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ContractStatus::options()),
            ])
            ->actions([
                Action::make('render_pdf')
                    ->label('Generate PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn (Contract $record) => app(ContractService::class)->renderPdf($record))
                    ->visible(fn (Contract $record): bool => ! $record->status->is(ContractStatus::Draft)),

                Action::make('send')
                    ->label('Send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->action(fn (Contract $record) => app(ContractService::class)->send($record))
                    ->visible(fn (Contract $record): bool => $record->content_snapshot !== null),

                Action::make('sign')
                    ->label('Mark Signed')
                    ->icon('heroicon-o-pencil-square')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Contract $record): void {
                        $record->update([
                            'status' => ContractStatus::Signed,
                            'signed_at' => now(),
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Contract marked as signed')
                            ->send();
                    })
                    ->visible(fn (Contract $record): bool => $record->status->is(ContractStatus::Draft, ContractStatus::AwaitingSignature)),

                Action::make('close')
                    ->label('Close Contract')
                    ->icon('heroicon-o-check-circle')
                    ->color('warning')
                    ->form([
                        Textarea::make('closing_notes')
                            ->label('Closing Notes'),
                        Toggle::make('has_damages')
                            ->label('Has Damages'),
                    ])
                    ->action(function (Contract $record, array $data): void {
                        $record->update([
                            'status' => ContractStatus::Closed,
                            'closed_at' => now(),
                            'closed_by_id' => Auth::id(),
                            'closing_notes' => $data['closing_notes'] ?? null,
                            'has_damages' => $data['has_damages'] ?? false,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Contract closed')
                            ->send();
                    })
                    ->visible(fn (Contract $record): bool => $record->status->is(ContractStatus::Active, ContractStatus::Signed)),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ContractResource\Pages\ListContracts::route('/'),
            'create' => ContractResource\Pages\CreateContract::route('/create'),
            'edit' => ContractResource\Pages\EditContract::route('/{record}/edit'),
        ];
    }
}
