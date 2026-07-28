<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CustomerResource\Pages;

use App\Filament\Admin\Resources\CustomerResource;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Profile')
                    ->schema([
                        TextEntry::make('code'),
                        TextEntry::make('type'),
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('date_of_birth')
                            ->date(),
                        TextEntry::make('place_of_birth'),
                        TextEntry::make('nationality'),
                        TextEntry::make('gender'),
                        TextEntry::make('national_id')
                            ->label('NIN'),
                        TextEntry::make('company_name'),
                        TextEntry::make('trade_register'),
                        TextEntry::make('tax_id'),
                        TextEntry::make('article_number'),
                    ])
                    ->columns(3),
                Section::make('Driving Licence')
                    ->schema([
                        TextEntry::make('driving_license_number'),
                        TextEntry::make('license_category'),
                        TextEntry::make('license_issue_date')
                            ->date(),
                        TextEntry::make('license_expiry_date')
                            ->date(),
                        TextEntry::make('license_issued_at'),
                    ])
                    ->columns(3),
                Section::make('Contact')
                    ->schema([
                        TextEntry::make('phone'),
                        TextEntry::make('phone_secondary'),
                        TextEntry::make('whatsapp'),
                        TextEntry::make('email'),
                        TextEntry::make('address'),
                        TextEntry::make('city'),
                        TextEntry::make('wilaya'),
                        TextEntry::make('country'),
                    ])
                    ->columns(3),
                Section::make('Commercial')
                    ->schema([
                        TextEntry::make('source'),
                        TextEntry::make('rating'),
                        TextEntry::make('is_blacklisted')
                            ->formatStateUsing(fn ($state) => $state ? 'Blacklisted' : 'Clear'),
                        TextEntry::make('blacklist_reason'),
                    ])
                    ->columns(2),
            ]);
    }
}
