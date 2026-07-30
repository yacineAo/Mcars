<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnershipAgreementResource\Pages;

use App\Filament\Admin\Resources\CarOwnershipAgreementResource;
use App\Filament\Admin\Resources\CarOwnershipAgreementResource\RelationManagers\InstallmentsRelationManager;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewCarOwnershipAgreement extends ViewRecord
{
    protected static string $resource = CarOwnershipAgreementResource::class;

    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Instalments'), [
                InstallmentsRelationManager::class,
            ]),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Parties')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('car.registration_number')
                            ->label('Car'),
                        TextEntry::make('carOwner.full_name')
                            ->label('Owner'),
                    ]),
                Section::make('Terms')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('model')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('monthly_rent_amount')
                            ->money('DZD')
                            ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
                        TextEntry::make('share_percentage')
                            ->suffix('%')
                            ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false),
                        TextEntry::make('start_date')
                            ->date(),
                        TextEntry::make('end_date')
                            ->date()
                            ->placeholder('—'),
                    ]),
                Section::make('Schedule')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('payment_day_of_month')
                            ->placeholder('—'),
                        TextEntry::make('installments_count')
                            ->placeholder('—'),
                        TextEntry::make('first_due_date')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('grace_days')
                            ->placeholder('—'),
                    ]),
                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->placeholder(__('No notes'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
