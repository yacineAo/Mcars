<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ChartOfAccountResource\Pages;

use App\Filament\Admin\Resources\ChartOfAccountResource;
use App\Filament\Admin\Resources\ChartOfAccountResource\RelationManagers\ChildrenRelationManager;
use App\Filament\Admin\Resources\ChartOfAccountResource\RelationManagers\TransactionsRelationManager;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewChartOfAccount extends ViewRecord
{
    protected static string $resource = ChartOfAccountResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Account'))
                    ->schema([
                        TextEntry::make('code'),
                        TextEntry::make('name'),
                        TextEntry::make('name_ar')->placeholder('—'),
                        TextEntry::make('name_fr')->placeholder('—'),
                        TextEntry::make('type')->badge(),
                        TextEntry::make('normal_balance')->badge(),
                        TextEntry::make('parent.name')
                            ->label('Parent')
                            ->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make(__('Flags'))
                    ->schema([
                        IconEntry::make('is_cash_equivalent')->boolean(),
                        IconEntry::make('is_postable')->boolean(),
                        IconEntry::make('is_system')->boolean(),
                        IconEntry::make('is_active')->boolean(),
                    ])
                    ->columns(4),
                Section::make(__('Notes'))
                    ->schema([
                        TextEntry::make('description')
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
            RelationGroup::make(__('Sub-accounts'), [
                ChildrenRelationManager::class,
            ]),
            RelationGroup::make(__('Transactions'), [
                TransactionsRelationManager::class,
            ]),
        ];
    }
}
