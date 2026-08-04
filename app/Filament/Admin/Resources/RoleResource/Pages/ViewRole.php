<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleResource\Pages;

use App\Filament\Admin\Resources\RoleResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * A role is a name plus a permission set — both already on the edit form
 * (docs/resource/34-role.md). This is the read-only mirror of the same two
 * facts, for a viewer who should not be able to change either.
 */
class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make()
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('filament-shield::filament-shield.field.name'))
                            ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                        TextEntry::make('guard_name')
                            ->label(__('filament-shield::filament-shield.field.guard_name'))
                            ->badge()
                            ->color('warning'),
                        TextEntry::make('permissions_count')
                            ->label(__('filament-shield::filament-shield.column.permissions'))
                            ->state(fn (Role $record): int => $record->permissions->count()),
                        TextEntry::make('updated_at')
                            ->label(__('filament-shield::filament-shield.column.updated_at'))
                            ->dateTime(),
                    ])
                    ->columns(4),
                Section::make(__('filament-shield::filament-shield.column.permissions'))
                    ->schema([
                        TextEntry::make('permissions')
                            ->label('')
                            ->state(fn (Role $record): array => $record->permissions->pluck('name')->sort()->values()->all())
                            ->listWithLineBreaks()
                            ->bulleted(),
                    ]),
            ]);
    }
}
