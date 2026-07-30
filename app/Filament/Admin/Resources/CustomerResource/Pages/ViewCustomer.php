<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CustomerResource\Pages;

use App\Filament\Admin\Resources\CustomerResource;
use App\Filament\Admin\Resources\CustomerResource\RelationManagers\BookingsRelationManager;
use App\Filament\Admin\Resources\CustomerResource\RelationManagers\ContractsRelationManager;
use App\Filament\Admin\Resources\CustomerResource\RelationManagers\DepositsRelationManager;
use App\Filament\Admin\Resources\CustomerResource\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\CustomerResource\RelationManagers\FinesRelationManager;
use App\Filament\Admin\Resources\CustomerResource\RelationManagers\PaymentSchedulesRelationManager;
use App\Filament\Admin\Resources\CustomerResource\RelationManagers\PaymentsRelationManager;
use App\Models\Customer;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    /** @var array<string, mixed>|null */
    protected ?array $statement = null;

    /**
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Rentals'), [
                BookingsRelationManager::class,
                ContractsRelationManager::class,
            ]),
            RelationGroup::make(__('Money'), [
                PaymentsRelationManager::class,
                DepositsRelationManager::class,
                PaymentSchedulesRelationManager::class,
            ]),
            RelationGroup::make(__('Fines'), [
                FinesRelationManager::class,
            ]),
            RelationGroup::make(__('Documents'), [
                DocumentsRelationManager::class,
            ]),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Profile'))
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
                        TextEntry::make('article_number'),
                    ])
                    ->columns(3),
                Section::make(__('Driving Licence'))
                    ->schema([
                        TextEntry::make('driving_license_number'),
                        TextEntry::make('license_category'),
                        TextEntry::make('license_issue_date')
                            ->date(),
                        TextEntry::make('license_expiry_date')
                            ->date()
                            ->badge()
                            ->color(fn (?string $state): string => match (true) {
                                $state === null => 'gray',
                                CarbonImmutable::parse($state)->isPast() => 'danger',
                                CarbonImmutable::parse($state)->isToday() => 'warning',
                                default => 'success',
                            })
                            ->formatStateUsing(function (?string $state): string {
                                if ($state === null) {
                                    return __('Not recorded');
                                }

                                $date = CarbonImmutable::parse($state);
                                $days = (int) now()->startOfDay()->diffInDays($date->startOfDay(), absolute: false);

                                return match (true) {
                                    $days < 0 => __(':date — expired :days days ago', ['date' => $date->format('d/m/Y'), 'days' => abs($days)]),
                                    $days === 0 => __(':date — expires today', ['date' => $date->format('d/m/Y')]),
                                    default => __(':date — :days days left', ['date' => $date->format('d/m/Y'), 'days' => $days]),
                                };
                            }),
                        TextEntry::make('license_issued_at'),
                    ])
                    ->columns(3),
                Section::make(__('Contact'))
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
                Section::make(__('Commercial'))
                    ->schema([
                        TextEntry::make('source'),
                        TextEntry::make('rating'),
                        TextEntry::make('is_blacklisted')
                            ->formatStateUsing(fn ($state) => $state ? __('Blacklisted') : __('Clear')),
                        TextEntry::make('blacklist_reason'),
                    ])
                    ->columns(2),
                Section::make(__('Financials'))
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('financials_invoiced')
                            ->label(__('Total Invoiced'))
                            ->state(fn ($record) => $this->money($record, 'invoiced')),
                        TextEntry::make('financials_paid')
                            ->label(__('Total Paid'))
                            ->state(fn ($record) => $this->money($record, 'paid')),
                        TextEntry::make('financials_owed')
                            ->label(__('Outstanding Balance (Owed)'))
                            ->state(fn ($record) => $this->money($record, 'owed'))
                            ->helperText(__('Negative means the customer is in credit.')),
                        TextEntry::make('financials_deposits_held')
                            ->label(__('Deposits Held'))
                            ->state(fn ($record) => $this->money($record, 'deposits_held'))
                            ->helperText(__('A liability owed back to the customer — never revenue.')),
                        TextEntry::make('financials_active_fines')
                            ->label(__('Active Fines'))
                            ->state(fn ($record) => $this->statement($record)['active_fines_count'] ?? 0),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * Resolved once per request rather than once per entry.
     *
     * @return array<string, mixed>
     */
    protected function statement(Customer $record): array
    {
        return $this->statement ??= app(ReportService::class)->customerStatement($record->id);
    }

    protected function money(Customer $record, string $key): string
    {
        return number_format((float) ($this->statement($record)[$key] ?? 0), 2).' DZD';
    }
}
