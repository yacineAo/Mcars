<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarResource\Pages;

use App\Filament\Admin\Resources\CarResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewCar extends ViewRecord
{
    protected static string $resource = CarResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identity')
                    ->schema([
                        TextEntry::make('brand'),
                        TextEntry::make('model'),
                        TextEntry::make('trim'),
                        TextEntry::make('year'),
                        TextEntry::make('color'),
                        TextEntry::make('registration_number')
                            ->label('Plate'),
                        TextEntry::make('chassis_number')
                            ->label('VIN'),
                        TextEntry::make('engine_number'),
                    ])
                    ->columns(3),
                Section::make('Status & Specs')
                    ->schema([
                        TextEntry::make('status'),
                        TextEntry::make('ownership_type'),
                        TextEntry::make('body_type'),
                        TextEntry::make('transmission'),
                        TextEntry::make('fuel_type'),
                        TextEntry::make('seats'),
                        TextEntry::make('doors'),
                        TextEntry::make('odometer')
                            ->suffix(' km'),
                        TextEntry::make('category.name'),
                    ])
                    ->columns(3),
                Section::make('Pricing')
                    ->schema([
                        TextEntry::make('daily_rate')
                            ->money('DZD'),
                        TextEntry::make('weekly_rate')
                            ->money('DZD'),
                        TextEntry::make('monthly_rate')
                            ->money('DZD'),
                        TextEntry::make('security_deposit_amount')
                            ->money('DZD'),
                        TextEntry::make('mileage_limit_per_day')
                            ->suffix(' km'),
                        TextEntry::make('extra_km_price')
                            ->money('DZD'),
                        TextEntry::make('late_hour_fee')
                            ->money('DZD'),
                    ])
                    ->columns(3),
                Section::make('Document Expiry')
                    ->schema([
                        TextEntry::make('insurance_expiry_date')
                            ->date(),
                        TextEntry::make('technical_inspection_expiry_date')
                            ->date(),
                        TextEntry::make('registration_expiry_date')
                            ->date(),
                        TextEntry::make('road_tax_expiry_date')
                            ->date(),
                    ])
                    ->columns(2),
            ]);
    }
}
