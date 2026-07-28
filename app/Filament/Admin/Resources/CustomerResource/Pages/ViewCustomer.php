<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CustomerResource\Pages;

use App\Filament\Admin\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\ReportService;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    /** @var array<string, mixed>|null */
    protected ?array $statement = null;

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
                Section::make('Financials')
                    ->visible(fn (): bool => Auth::user()?->can('reports.view_financials') ?? false)
                    ->schema([
                        TextEntry::make('financials_invoiced')
                            ->label('Total Invoiced')
                            ->state(fn ($record) => $this->money($record, 'invoiced')),
                        TextEntry::make('financials_paid')
                            ->label('Total Paid')
                            ->state(fn ($record) => $this->money($record, 'paid')),
                        TextEntry::make('financials_owed')
                            ->label('Outstanding Balance (Owed)')
                            ->state(fn ($record) => $this->money($record, 'owed'))
                            ->helperText('Negative means the customer is in credit.'),
                        TextEntry::make('financials_deposits_held')
                            ->label('Deposits Held')
                            ->state(fn ($record) => $this->money($record, 'deposits_held'))
                            ->helperText('A liability owed back to the customer — never revenue.'),
                        TextEntry::make('financials_active_fines')
                            ->label('Active Fines')
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
