<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\VendorType;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\VendorResource\Pages\CreateVendor;
use App\Filament\Admin\Resources\VendorResource\Pages\EditVendor;
use App\Filament\Admin\Resources\VendorResource\Pages\ListVendors;
use App\Filament\Admin\Resources\VendorResource\Pages\ViewVendor;
use App\Models\Vendor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class VendorResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|UnitEnum|null $navigationGroup = 'Fleet';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('fleet.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('fleet.manage') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('fleet.manage') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('type')
                    ->options(VendorType::options())
                    ->required(),
                TextInput::make('contact_name')
                    ->maxLength(255),
                TextInput::make('phone')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                Textarea::make('address')
                    ->maxLength(65535),
                Section::make('Payment Details')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextInput::make('bank_account_number')
                            ->maxLength(255),
                        TextInput::make('rib')
                            ->maxLength(50),
                        TextInput::make('ccp_number')
                            ->maxLength(50),
                    ])
                    ->columns(3),
                Textarea::make('notes')
                    ->maxLength(65535),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('maintenanceLogs'))
            ->columns([
                TextColumn::make('name')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('contact_name')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('maintenance_logs_count')
                    ->label('Services')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(VendorType::options()),
                TernaryFilter::make('is_active')
                    ->default(true),
                SelectFilter::make('branch_id')
                    ->label(__('Branch'))
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
            ])
            ->defaultSort('name')
            ->headerActions([])
            ->recordActions([
                Action::make('deactivate')
                    ->label(__('Deactivate'))
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (Vendor $record): bool => $record->is_active
                        && (Auth::user()?->can('fleet.manage') ?? false))
                    ->requiresConfirmation()
                    ->action(function (Vendor $record): void {
                        $record->update(['is_active' => false]);

                        Notification::make()
                            ->success()
                            ->title(__('Vendor deactivated'))
                            ->send();
                    }),

                Action::make('reactivate')
                    ->label(__('Reactivate'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Vendor $record): bool => ! $record->is_active
                        && (Auth::user()?->can('fleet.manage') ?? false))
                    ->requiresConfirmation()
                    ->action(function (Vendor $record): void {
                        $record->update(['is_active' => true]);

                        Notification::make()
                            ->success()
                            ->title(__('Vendor reactivated'))
                            ->send();
                    }),

                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (): bool => Auth::user()?->can('fleet.manage') ?? false),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'view' => ViewVendor::route('/{record}'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }
}
