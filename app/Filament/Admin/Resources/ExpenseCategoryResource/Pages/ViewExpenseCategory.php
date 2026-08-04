<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExpenseCategoryResource\Pages;

use App\Filament\Admin\Resources\ExpenseCategoryResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewExpenseCategory extends ViewRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextEntry::make('name'),
                TextEntry::make('name_ar')->placeholder('—'),
                TextEntry::make('name_fr')->placeholder('—'),
                TextEntry::make('slug'),
                TextEntry::make('parent.name')
                    ->label('Parent')
                    ->placeholder('—'),
                TextEntry::make('ledgerAccount.name')
                    ->label('Ledger Account'),
                TextEntry::make('sort_order'),
                IconEntry::make('is_car_related')->boolean(),
                IconEntry::make('is_recurring_default')->boolean(),
                IconEntry::make('is_active')->boolean(),
            ])
            ->columns(3);
    }
}
