<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarCategoryResource\Pages;

use App\Filament\Admin\Resources\CarCategoryResource;
use App\Models\CarCategory;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewCarCategory extends ViewRecord
{
    protected static string $resource = CarCategoryResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextEntry::make('name'),
                TextEntry::make('slug'),
                TextEntry::make('description')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('sort_order'),
                TextEntry::make('cars_count')
                    ->label('Cars')
                    ->state(fn (CarCategory $record): int => $record->cars()->count()),
                IconEntry::make('is_active')
                    ->boolean(),
            ])
            ->columns(3);
    }
}
