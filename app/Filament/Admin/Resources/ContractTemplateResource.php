<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\Locale;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Models\ContractTemplate;
use App\Services\Booking\ContractService;
use App\Support\ContractTemplatePreview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ContractTemplateResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = ContractTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Bookings';

    /**
     * Reading and writing split along the same line as the rest of the bookings
     * catalogue (see RolePermissionSeeder): every staff role except the maintenance
     * officer reads the terms — a receptionist explaining them to a customer needs
     * them — while rewriting the legal boilerplate the business rents cars under is
     * the manager's call alone.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('bookings.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        if (! ($record instanceof ContractTemplate)) {
            return false;
        }

        // A template a contract was rendered from is the provenance of a legal
        // document, even though the contract keeps its own content_snapshot.
        if ($record->hasRenderedContracts()) {
            return false;
        }

        return static::canManage();
    }

    public static function canManage(): bool
    {
        return Auth::user()?->can('bookings.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')->required(),
                Select::make('locale')
                    ->options(Locale::options())
                    ->required()
                    ->disabled(fn (?ContractTemplate $record): bool => $record?->hasRenderedContracts() ?? false)
                    ->helperText(fn (?ContractTemplate $record): ?string => $record?->hasRenderedContracts() ?? false
                        ? __('Locale cannot be changed once contracts have been rendered from this template.')
                        : null),
                Textarea::make('body')
                    ->label(__('Template body'))
                    ->required()
                    ->rows(20)
                    ->columnSpanFull()
                    ->helperText(fn (): Htmlable => ContractTemplatePreview::reference()),
                TextInput::make('terms_version')
                    ->default(ContractTemplate::INITIAL_TERMS_VERSION)
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(__('Increments automatically when the body changes.')),
                Toggle::make('is_active')->default(true),
                Toggle::make('is_default')->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // The delete guard asks whether any contract was rendered from each row.
            // Pre-computing it here keeps that one query instead of two per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withExists('contracts'))
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('locale')
                    ->formatStateUsing(fn (Locale $state): string => $state->getLabel()),
                TextColumn::make('terms_version'),
                IconColumn::make('is_active')->boolean(),
                IconColumn::make('is_default')->boolean(),
            ])
            ->filters([
                SelectFilter::make('locale')
                    ->options(Locale::options()),
            ])
            ->actions([
                Action::make('set_default')
                    ->label(__('Set Default'))
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (ContractTemplate $record): void {
                        app(ContractService::class)->setDefaultTemplate($record);

                        Notification::make()
                            ->success()
                            ->title(__('Template set as default for :locale', [
                                'locale' => $record->locale->getLabel(),
                            ]))
                            ->send();
                    })
                    ->visible(fn (ContractTemplate $record): bool => ! $record->is_default && static::canManage()),
                ViewAction::make(),
                // Filament's EditAction/DeleteAction do not consult the resource's
                // canEdit()/canDelete() — those guard the *pages*, and a table action
                // runs in place without visiting one. Without these the read-only
                // roles could delete a template straight from the list.
                EditAction::make()
                    ->visible(fn (): bool => static::canManage()),
                DeleteAction::make()
                    ->visible(fn (ContractTemplate $record): bool => static::canDelete($record)),
            ])
            ->bulkActions([])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ContractTemplateResource\Pages\ListContractTemplates::route('/'),
            'create' => ContractTemplateResource\Pages\CreateContractTemplate::route('/create'),
            'view' => ContractTemplateResource\Pages\ViewContractTemplate::route('/{record}'),
            'edit' => ContractTemplateResource\Pages\EditContractTemplate::route('/{record}/edit'),
        ];
    }
}
