<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\RoleResource\Pages\EditRole;
use App\Filament\Admin\Resources\RoleResource\Pages\ListRoles;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The role/permission screen, and the most sensitive surface in the panel:
 * editing a role changes who can do what. docs/resource/34-role.md was a litany
 * of privilege problems — no canAccess(), no policy, and Filament's `can()`
 * treats a missing policy as granted, so *every* staff role (receptionist
 * included) could open it; the permission tabs rendered none of the four
 * real permissions while displaying 456 inert Shield checkboxes, so saving
 * any role stripped the seeded set from it; and delete could lock the only
 * account with a way back out.
 *
 * What remains after Round 34 is deliberately small. The role list is
 * `UserRole` — a fixed enum — and RolePermissionSeeder is the source of
 * truth, so there is no create page and no delete path. The edit form shows
 * exactly the permissions the application enforces (config
 * `filament-shield.custom_permissions`), the name is frozen once assigned
 * (everything in the app matches on the string — `assignRole()`,
 * `AlertRule::recipient_roles`, the seeder), and `guard_name` is hidden
 * (everything here is `web`).
 */
class RoleResource extends ShieldRoleResource
{
    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    /**
     * Who may edit who-can-do-what. Same spread as users.manage on purpose —
     * the pair of screens can never drift apart. Anything a receptionist may
     * do inside the panel, they may do without ever opening this screen.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('roles.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make()
                    ->schema([
                        Section::make()
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('filament-shield::filament-shield.field.name'))
                                    ->required()
                                    ->maxLength(255)
                                    // Frozen once assigned: name is the string
                                    // UserRole, the seeder, AlertRule::recipient_roles
                                    // and every assignRole() call match on. Renaming
                                    // `manager` would silently detach the role from
                                    // all four. Not dehydrated — the save never
                                    // touches it.
                                    ->disabled(),
                                TextInput::make('guard_name')
                                    ->label(__('filament-shield::filament-shield.field.guard_name'))
                                    // Everything here is the web guard, so the field
                                    // is hidden rather than editable. It stays in the
                                    // form state (hidden fields dehydrate) so Shield's
                                    // EditRole::afterSave() resolves permissions with
                                    // the same guard_name it created them under.
                                    ->default(Utils::getFilamentAuthGuard())
                                    ->nullable()
                                    ->maxLength(255)
                                    ->hidden(),
                                static::getSelectAllFormComponent(),
                            ])
                            ->columns([
                                'sm' => 2,
                                'lg' => 3,
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                // Pages/widgets/resources tabs are off in config; this renders
                // the custom tab only — the one list of permissions the app
                // actually enforces.
                static::getShieldFormComponents(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->weight(FontWeight::Medium)
                    ->label(__('filament-shield::filament-shield.column.name'))
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->searchable(),
                TextColumn::make('guard_name')
                    ->badge()
                    ->color('warning')
                    ->label(__('filament-shield::filament-shield.column.guard_name')),
                TextColumn::make('permissions_count')
                    ->badge()
                    ->label(__('filament-shield::filament-shield.column.permissions'))
                    ->counts('permissions')
                    ->color('primary'),
                TextColumn::make('updated_at')
                    ->label(__('filament-shield::filament-shield.column.updated_at'))
                    ->dateTime(),
            ])
            // Edit only. Delete is gone: deleting a role locks its holders out
            // of the panel (canAccessPanel() requires a UserRole case), and the
            // seeder is the only sanctioned writer of the role list anyway.
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
