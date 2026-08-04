<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorResource\Pages;

use App\Filament\Admin\Resources\VendorResource;
use App\Filament\Admin\Resources\VendorResource\RelationManagers\ExpensesRelationManager;
use App\Filament\Admin\Resources\VendorResource\RelationManagers\MaintenanceLogsRelationManager;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewVendor extends ViewRecord
{
    protected static string $resource = VendorResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Details'))
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('type')->badge(),
                        TextEntry::make('contact_name')->placeholder('—'),
                        TextEntry::make('phone')->placeholder('—'),
                        TextEntry::make('email')->placeholder('—'),
                        TextEntry::make('address')->placeholder('—')->columnSpanFull(),
                        IconEntry::make('is_active')->boolean(),
                    ])
                    ->columns(3),
                Section::make(__('Payment Details'))
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('bank_account_number')->placeholder('—'),
                        TextEntry::make('rib')->placeholder('—'),
                        TextEntry::make('ccp_number')->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make(__('Notes'))
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
            RelationGroup::make(__('Service history'), [
                MaintenanceLogsRelationManager::class,
            ]),
            RelationGroup::make(__('Expenses'), [
                ExpensesRelationManager::class,
            ]),
        ];
    }
}
