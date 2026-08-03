<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarOwnerResource\Pages;

use App\Enums\Wilaya;
use App\Filament\Admin\Resources\CarOwnerResource;
use App\Filament\Admin\Resources\CarOwnerResource\RelationManagers\CarsRelationManager;
use App\Filament\Admin\Resources\CarOwnerResource\RelationManagers\InstallmentsRelationManager;
use App\Filament\Admin\Resources\CarOwnerResource\RelationManagers\PaymentsRelationManager;
use App\Models\CarOwner;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewCarOwner extends ViewRecord
{
    protected static string $resource = CarOwnerResource::class;

    /** @var array<string, mixed>|null */
    protected ?array $statement = null;

    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Cars'), [
                CarsRelationManager::class,
            ]),
            RelationGroup::make(__('Instalments'), [
                InstallmentsRelationManager::class,
            ]),
            RelationGroup::make(__('Payments'), [
                PaymentsRelationManager::class,
            ]),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identity & Contact')
                    ->schema([
                        TextEntry::make('type')
                            ->badge(),
                        TextEntry::make('first_name'),
                        TextEntry::make('last_name'),
                        TextEntry::make('company_name')
                            ->placeholder('—'),
                        TextEntry::make('trade_register')
                            ->placeholder('—'),
                        TextEntry::make('national_id')
                            ->label('NIN')
                            ->placeholder('—'),
                        TextEntry::make('phone'),
                        TextEntry::make('whatsapp')
                            ->placeholder('—'),
                        TextEntry::make('email')
                            ->placeholder('—'),
                        TextEntry::make('address')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('wilaya')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): ?string => $state === null
                                ? null
                                : (Wilaya::tryFrom($state)?->getLabel() ?? $state)),
                    ])
                    ->columns(3),
                Section::make('Payment Details')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('bank_name')
                            ->placeholder('—'),
                        TextEntry::make('bank_rib')
                            ->label('RIB')
                            ->placeholder('—'),
                        TextEntry::make('ccp_account')
                            ->label('CCP')
                            ->placeholder('—'),
                        TextEntry::make('baridimob_number')
                            ->label('BaridiMob')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Statement')
                    ->description(fn (): string => __('Lifetime summary'))
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('statement_total_due')
                            ->label('Total Due')
                            ->state(fn (CarOwner $record): string => $this->money($this->statement($record), 'total_due')),
                        TextEntry::make('statement_total_paid')
                            ->label('Total Paid')
                            ->state(fn (CarOwner $record): string => $this->money($this->statement($record), 'total_paid')),
                        TextEntry::make('statement_balance')
                            ->label('Balance')
                            ->state(fn (CarOwner $record): string => $this->money($this->statement($record), 'balance')),
                    ])
                    ->columns(3),
                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->placeholder(__('No notes'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function statement(CarOwner $record): array
    {
        if ($this->statement === null) {
            $today = CarbonImmutable::today();

            $this->statement = app(ReportService::class)->ownerStatement(
                $record->id,
                $record->created_at ?? $today->subYear(),
                $today,
            );
        }

        return $this->statement;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function money(array $data, string $key): string
    {
        return number_format((float) ($data[$key] ?? 0), 2).' DZD';
    }
}
