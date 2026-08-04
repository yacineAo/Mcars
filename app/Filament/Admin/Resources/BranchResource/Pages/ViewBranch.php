<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BranchResource\Pages;

use App\Enums\Wilaya;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\BranchResource\RelationManagers\UsersRelationManager;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewBranch extends ViewRecord
{
    protected static string $resource = BranchResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identity')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('code')->badge(),
                        IconEntry::make('is_default')
                            ->label(__('branches.columns.default_badge'))
                            ->boolean(),
                        IconEntry::make('is_active')
                            ->boolean(),
                    ])
                    ->columns(4),
                Section::make('Location')
                    ->schema([
                        TextEntry::make('address')->placeholder('—'),
                        TextEntry::make('city')->placeholder('—'),
                        TextEntry::make('wilaya')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): ?string => $state === null
                                ? null
                                : (Wilaya::tryFrom($state)?->getLabel() ?? $state)),
                        TextEntry::make('timezone'),
                    ])
                    ->columns(2),
                Section::make('Contact')
                    ->schema([
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('email')->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Management')
                    ->schema([
                        TextEntry::make('manager.name')
                            ->label(__('branches.columns.manager'))
                            ->placeholder('—'),
                    ]),
                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->label('')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }
}
