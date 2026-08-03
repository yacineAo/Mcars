<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The branches a user is assigned to — `User::accessibleBranchIds()` reads this
 * pivot table first, so multi-branch assignment is the difference between a user
 * who sees the branches they work in and one who sees nothing. Maintained in place
 * on the user's edit page.
 */
class BranchUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'branchUsers';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('code')
                    ->badge(),
                IconColumn::make('pivot.is_primary')
                    ->boolean(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label(__('users.actions.assign_branch'))
                    ->recordSelect(fn (Select $select): Select => $select->label(__('users.resources.branch')))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Toggle::make('is_primary')
                            ->label(__('Primary')),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([
                        Toggle::make('is_primary')
                            ->label(__('Primary')),
                    ]),
                DetachAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('users.resources.branch_assignments');
    }
}
