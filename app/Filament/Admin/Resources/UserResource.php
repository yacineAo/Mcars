<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\Locale;
use App\Enums\UserRole;
use App\Filament\Admin\Concerns\TranslatesModelLabel;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
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
     * themselves — to super_admin. Used both for the Select's options and for the
     * validation rule that enforces it server-side, so the two cannot drift.
     *
     * @return array<int|string, string>
     */
    public static function assignableRoles(): array
    {
        $user = Auth::user();

        if ($user === null) {
            return [];
        }

        $roles = Role::query()->orderBy('name')->pluck('name', 'id');

        // Holding every permission is not the same as holding the role, so this checks the
        // role itself — the only thing that can grant it onward.
        if ($user->hasRole(UserRole::SuperAdmin->value)) {
            return $roles->all();
        }

        return $roles->reject(fn (string $name): bool => $name === UserRole::SuperAdmin->value)->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->hiddenOn('edit')
                    ->maxLength(255),
                TextInput::make('phone')
                    ->tel()
                    ->maxLength(255),
                TextInput::make('whatsapp')
                    ->maxLength(255),
                Select::make('locale')
                    ->options(Locale::options())
                    ->required(),
                Toggle::make('is_active'),
                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->options(fn (): array => self::assignableRoles())
                    ->multiple()
                    ->preload()
                    // Filtering the options hides super_admin from a manager, but hiding is
                    // not security: the field posts role ids and a crafted request can name
                    // any of them. This rule is the server-side half, checked against the
                    // same assignableRoles() list the options come from so the rule has one
                    // definition.
                    //
                    // It lives here rather than in a page hook because `roles` is a
                    // relationship field — Filament syncs it separately and it never appears
                    // in the data passed to mutateFormDataBeforeCreate/Save.
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $allowed = array_map('strval', array_keys(self::assignableRoles()));
                        $submitted = array_map('strval', (array) $value);

                        if (array_diff($submitted, $allowed) !== []) {
                            $fail(__('users.errors.role_not_assignable'));
                        }
                    }),
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
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('locale'),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('roles.name')
                    ->badge(),
            ])
            ->filters([])
            ->actions([
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
