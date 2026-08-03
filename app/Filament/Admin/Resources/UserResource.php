<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\Locale;
use App\Enums\UserRole;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Filament\Admin\Resources\UserResource\RelationManagers;
use App\Models\User;
use App\Services\UserService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * @property Schema $form
 */
class UserResource extends Resource
{
    use TranslatesModelLabel;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    /**
     * `users.manage`, not `branches.view_all`.
     *
     * The old gate was semantically wrong — `branches.view_all` governs cross-branch
     * *visibility*, and granting it to a manager (which the seeder does, correctly) also
     * handed them staff-account management as a side effect. Managing accounts deserves
     * its own permission, which is what the docs mean by "Settings & Access: manager full"
     * in docs/02-filament-panels.md §Role → visibility matrix.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('users.manage') ?? false;
    }

    /**
     * Roles a user may hand out: never one they do not hold themselves.
     *
     * A manager is meant to create receptionists, not promote anybody — including
     * themselves — to super_admin. Used both for the action's options and for the
     * validation that enforces it server-side, so the two cannot drift.
     *
     * @return array<string, string> role name => label
     */
    public static function assignableRoles(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return collect(app(UserService::class)->assignableRoleNames($user))
            ->mapWithKeys(fn (string $name): array => [
                $name => UserRole::tryFrom($name)?->getLabel() ?? $name,
            ])
            ->all();
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
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(255)
                            // The column carries users_phone_unique and the form
                            // never validated it, so a duplicate reached Postgres as
                            // a raw QueryException. nullable() keeps a blank field
                            // posting null, which the unique index tolerates.
                            ->nullable()
                            ->unique(ignoreRecord: true),
                        TextInput::make('whatsapp')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make('Access')
                    ->schema([
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->hiddenOn('edit')
                            ->rule(Password::default())
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->default(true),
                    ])
                    ->columns(2),

                // Roles are deliberately NOT here. Assigning a role is an access
                // decision with an audit trail, made through the Assign roles action
                // (UserResource::assignRolesAction()) — never a multi-select buried in
                // a form that a manager could use to promote themselves.

                Section::make('Placement')
                    ->schema([
                        Select::make('branch_id')
                            ->label(__('Branch'))
                            ->relationship('branch', 'name')
                            // Placement is who a user works for. Without it a created
                            // account falls through accessibleBranchIds() to the
                            // report() branch and sees nothing in branch-pinned logs.
                            ->preload()
                            ->searchable(),
                    ])
                    ->columns(2),

                Section::make('Preferences')
                    ->schema([
                        Select::make('locale')
                            ->options(Locale::options())
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->badge()
                    ->color(fn (string $state): string => UserRole::tryFrom($state)?->getColor() ?? 'gray')
                    ->formatStateUsing(fn (string $state): string => UserRole::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('branch.name')
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->options(fn (): array => Role::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->mapWithKeys(fn (string $name, int $id): array => [
                            $id => UserRole::tryFrom($name)?->getLabel() ?? $name,
                        ])
                        ->all()),
                TernaryFilter::make('is_active')
                    ->default(true),
                // Cross-branch visibility is a separate permission: someone who may
                // manage accounts but not see other branches' data filters by their
                // own. In practice today's users.manage holders also hold
                // branches.view_all, so this stays visible, but the gate is the
                // capability, not the coincidence.
                SelectFilter::make('branch_id')
                    ->label(__('Branch'))
                    ->relationship('branch', 'name')
                    ->visible(fn (): bool => Auth::user()?->can('branches.view_all') ?? false),
            ])
            // Eager-load the per-row relations: the roles badge is a lookup per role
            // and the branch column one per row without this.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['roles', 'branch']))
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                self::assignRolesAction(),
                self::resetPasswordAction(),
                self::setActiveAction(),
                // The change trail for this record, pre-filtered to it (ADV-03).
                Action::make('view_history')
                    ->label(__('activity_log.resource.actions.view_history'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->url(fn (User $record): string => ActivityLogResource::getUrl('index', [
                        'tableFilters' => [
                            'subject_type' => ['value' => User::class],
                            'subject_id' => ['subject_id' => $record->getKey()],
                        ],
                    ], panel: 'admin'))
                    ->visible(fn (): bool => (bool) (Auth::user()?->can('audit.view') ?? false)),
            ])
            // No bulk delete: staff accounts are parked, not destroyed — the audit
            // trail and the ledger attribution point at them.
            ->bulkActions([]);
    }

    /**
     * The only path for changing a user's roles.
     *
     * Never available on the acting user's own record — the self-promotion vector the
     * old in-form Select exposed. The service re-checks assignability server-side, so
     * the option list is convenience, not security.
     */
    public static function assignRolesAction(): Action
    {
        return Action::make('assign_roles')
            ->label(__('users.actions.assign_roles'))
            ->icon('heroicon-o-user-group')
            ->color('info')
            ->fillForm(fn (Action $action): array => [
                'roles' => $action->getRecord() instanceof User
                    ? $action->getRecord()->getRoleNames()->all()
                    : [],
            ])
            ->form([
                CheckboxList::make('roles')
                    ->options(fn (): array => self::assignableRoles()),
            ])
            ->action(function (User $record, array $data, UserService $service): void {
                try {
                    $service->assignRoles($record, $data['roles'] ?? [], Auth::user());
                } catch (DomainException $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('users.notifications.roles_updated'))
                    ->send();
            })
            ->visible(fn (User $record): bool => $record->isNot(Auth::user()));
    }

    /**
     * Sets a new password and forces a change at the user's next login.
     *
     * The password field is hidden from the edit form by design — resetting a password
     * is an action with a confirmation step, not a form field, and the reset is what
     * flips must_change_password on.
     */
    public static function resetPasswordAction(): Action
    {
        return Action::make('reset_password')
            ->label(__('users.actions.reset_password'))
            ->icon('heroicon-o-key')
            ->color('warning')
            ->modalHeading(__('users.actions.reset_password_heading'))
            ->form([
                TextInput::make('password')
                    ->label(__('users.actions.new_password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->same('password_confirmation'),
                TextInput::make('password_confirmation')
                    ->label(__('users.actions.new_password_confirmation'))
                    ->password()
                    ->required()
                    ->dehydrated(false),
            ])
            ->action(function (User $record, array $data, UserService $service): void {
                try {
                    $service->resetPassword($record, $data['password'], Auth::user());
                } catch (DomainException $e) {
                    Notification::make()
                        ->danger()
                        ->title($e->getMessage())
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('users.notifications.password_reset'))
                    ->send();
            });
    }

    /**
     * Deactivate / reactivate — the intended replacement for delete. Deleting a user
     * either violated ledger foreign keys (raw QueryException) or nulled out the
     * attribution columns that ARE the audit trail; a parked account keeps the rows
     * pointing at a person. is_active also gates panel access on every request, so
     * deactivating is immediate, even for an open tab.
     */
    public static function setActiveAction(): Action
    {
        return Action::make('set_active')
            ->label(fn (User $record): string => $record->is_active
                ? __('users.actions.deactivate')
                : __('users.actions.reactivate'))
            ->icon(fn (User $record): string => $record->is_active
                ? 'heroicon-o-pause-circle'
                : 'heroicon-o-play-circle')
            ->color(fn (User $record): string => $record->is_active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (User $record): string => $record->is_active
                ? __('users.actions.deactivate_confirm')
                : __('users.actions.reactivate_confirm'))
            ->action(function (User $record, UserService $service): void {
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
                        ? __('users.notifications.reactivated')
                        : __('users.notifications.deactivated'))
                    ->send();
            })
            ->visible(fn (User $record): bool => $record->isNot(Auth::user()));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Branch assignments are maintained in place on the edit page — there is no
     * view page, and the office maintains them where it maintains the person.
     */
    public static function getRelations(): array
    {
        return [
            RelationManagers\BranchUsersRelationManager::class,
        ];
    }
}
