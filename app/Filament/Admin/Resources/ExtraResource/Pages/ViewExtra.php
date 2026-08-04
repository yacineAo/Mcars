<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExtraResource\Pages;

use App\Enums\ExtraPricingUnit;
use App\Filament\Admin\Resources\ExtraResource;
use App\Models\Extra;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewExtra extends ViewRecord
{
    protected static string $resource = ExtraResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                TextEntry::make('code'),
                TextEntry::make('name'),
                TextEntry::make('pricing_unit')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null
                        ? null
                        : (ExtraPricingUnit::tryFrom($state)?->getLabel() ?? $state)),
                TextEntry::make('unit_price')->money('DZD'),
                TextEntry::make('ledgerAccount.name')->label('Ledger Account'),
                TextEntry::make('booking_extras_count')
                    ->label('Times sold')
                    ->state(fn (Extra $record): int => $record->bookingExtras()->count()),
                IconEntry::make('is_active')->boolean(),
            ])
            ->columns(3);
    }
}
