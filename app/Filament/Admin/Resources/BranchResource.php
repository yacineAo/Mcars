<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\Wilaya;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\BranchResource\Pages\CreateBranch;
use App\Filament\Admin\Resources\BranchResource\Pages\EditBranch;
use App\Filament\Admin\Resources\BranchResource\Pages\ListBranches;
use App\Filament\Admin\Resources\BranchResource\Pages\ViewBranch;
use App\Filament\Admin\Resources\BranchResource\RelationManagers;
use App\Models\Branch;
use App\Rules\UniqueBranchCode;
use App\Services\BranchService;
use BackedEnum;
use DateTimeZone;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * @property Schema $form
 */
class BranchResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('branches.view_all') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identity')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->required()
                            ->alphaDash()
                            ->maxLength(8)
                            ->helperText(__('branches.help.code'))
                            ->rule(fn (?Branch $record): UniqueBranchCode => new UniqueBranchCode($record?->id))
                            // A code is the address of a document-numbering universe
                            // ("CTR-MAIN-2026-000123"). Once a single document has been
                            // numbered it is frozen forever: renaming would mislabel
                            // every document an office has already printed.
                            ->disabled(fn (?Branch $record): bool => $record?->hasNumberedDocuments() ?? false),
                    ])
                    ->columns(2),

                Section::make('Location')
                    ->schema([
                        TextInput::make('address')
                            ->maxLength(255),
                        TextInput::make('city')
                            ->maxLength(255),
                        Select::make('wilaya')
                            ->options(Wilaya::options())
                            ->searchable(),
                        Select::make('timezone')
                            ->options(self::timezoneOptions())
                            ->default('Africa/Algiers'),
                    ])
                    ->columns(2),

                Section::make('Contact')
                    ->schema([
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(32),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Management')
                    ->schema([
                        Select::make('manager_user_id')
                            ->label(__('branches.columns.manager'))
                            ->relationship('manager', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText(__('branches.help.manager')),
                        Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->columns(2),

                // is_default is deliberately absent. "Which branch is the default" is
                // a property of the *set* — exactly one of them is, enforced by a
                // partial unique index — never a checkbox a form can tick into a
                // second winner. Promotion goes through the make-default row action.

                Section::make('Notes')
                    ->schema([
                        Textarea::make('notes')
                            ->maxLength(65535),
                    ])
                    ->columns(1),

                Placeholder::make('created_at')
                    ->content(fn (Branch $record): ?string => $record->created_at?->format('Y-m-d H:i:s'))
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('code')
                    ->badge()
                    ->searchable(),
                TextColumn::make('manager.name')
                    ->label(__('branches.columns.manager'))
                    ->placeholder('—'),
                TextColumn::make('users_count')
                    ->label(__('branches.columns.users'))
                    ->counts('users'),
                TextColumn::make('wilaya')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : (Wilaya::tryFrom($state)?->getLabel() ?? $state)),
                TextColumn::make('is_default')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn (bool $state): ?string => $state ? __('branches.columns.default_badge') : null),
                TextColumn::make('is_active')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->default(true),
                SelectFilter::make('wilaya')
                    ->options(Wilaya::options()),
            ])
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('manager'))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::makeDefaultAction(),
                self::setActiveAction(),
            ])
            // No bulk delete: a branch is wound down, never destroyed in bulk —
            // see BranchService::delete() and the delete action on the edit page.
            ->bulkActions([]);
    }

    /**
     * Promotes this branch to the single default. Hidden on the default row and
     * on inactive branches — the service re-checks both server-side, the
     * visibility is just UX.
     */
    public static function makeDefaultAction(): Action
    {
        return Action::make('make_default')
            ->label(__('branches.actions.make_default'))
            ->icon('heroicon-o-star')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('branches.actions.make_default_confirm'))
            ->action(function (Branch $record, BranchService $service): void {
                try {
                    $service->makeDefault($record, Auth::user());
                } catch (DomainException $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('branches.notifications.made_default'))
                    ->send();
            })
            ->visible(fn (Branch $record): bool => ! $record->is_default && $record->is_active);
    }

    /**
     * Deactivate / reactivate — the intended replacement for delete. Deleting a
     * branch either violates the ledger's foreign keys or nulls out the
     * attribution columns that ARE the audit trail; a parked branch keeps every
     * historical row pointed at the office that recorded it.
     */
    public static function setActiveAction(): Action
    {
        return Action::make('set_active')
            ->label(fn (Branch $record): string => $record->is_active
                ? __('branches.actions.deactivate')
                : __('branches.actions.reactivate'))
            ->icon(fn (Branch $record): string => $record->is_active
                ? 'heroicon-o-pause-circle'
                : 'heroicon-o-play-circle')
            ->color(fn (Branch $record): string => $record->is_active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (Branch $record): string => $record->is_active
                ? __('branches.actions.deactivate_confirm')
                : __('branches.actions.reactivate_confirm'))
            ->action(function (Branch $record, BranchService $service): void {
                try {
                    $service->setActive($record, ! $record->is_active, Auth::user());
                } catch (DomainException $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title($record->is_active
                        ? __('branches.notifications.reactivated')
                        : __('branches.notifications.deactivated'))
                    ->send();
            })
            // The default branch can never be deactivated (BranchService refuses),
            // so its row carries no set-active action at all; reactivate appears
            // only for parked branches, which are by definition not default.
            ->visible(fn (Branch $record): bool => ! $record->is_default);
    }

    /** @return array<string, string> Africa/* identifiers, alphabetical (Africa/Abidjan first). */
    private static function timezoneOptions(): array
    {
        $zones = DateTimeZone::listIdentifiers(DateTimeZone::AFRICA);

        return array_combine($zones, $zones) ?: [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'view' => ViewBranch::route('/{record}'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\UsersRelationManager::class,
        ];
    }
}
