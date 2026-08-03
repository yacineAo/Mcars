<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BranchResource\RelationManagers;

use App\Enums\UserRole;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The staff whose home branch this is — read-only.
 *
 * Round 35: the branch screen used to leave users unreachable from the other
 * direction (a user's edit page lists the branches they belong to, but a
 * branch's edit page had nothing about its people). Placement and role
 * management stay on the UserResource — this list exists to answer "who works
 * here?" in one glance, so it cannot create, edit or detach anyone.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    /**
     * People are as sensitive as the branch: listing staff requires the same
     * capability the UserResource itself requires.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('users.manage') ?? false;
    }

    public function table(Table $table): Table
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
                TextColumn::make('is_active')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
            ])
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('roles'))
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
