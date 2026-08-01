<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentResource\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Admin\Resources\BookingResource;
use App\Filament\Admin\Resources\ContractResource;
use App\Filament\Admin\Resources\CustomerResource;
use App\Filament\Admin\Resources\DepositResource;
use App\Filament\Admin\Resources\ExpenseResource;
use App\Filament\Admin\Resources\FineResource;
use App\Filament\Admin\Resources\OwnerInstallmentResource;
use App\Filament\Admin\Resources\PaymentResource;
use App\Filament\Admin\Resources\PaymentResource\RelationManagers\TransactionsRelationManager;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\Fine;
use App\Models\OwnerInstallment;
use App\Models\Payment;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The reconciliation screen: what the payment was for and whether it reached
 * the ledger. The postings relation manager answers the second half, gated by
 * reports.view_financials.
 */
class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    /** @var array{label: ?string, url: ?string}|null */
    private ?array $payableCache = null;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('Identity'))
                    ->schema([
                        TextEntry::make('reference')->label(__('Reference')),
                        TextEntry::make('direction')->badge(),
                        TextEntry::make('method'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('branch.name')->label(__('payments.fields.branch')),
                    ])
                    ->columns(4),

                Section::make(__('Money'))
                    ->schema([
                        TextEntry::make('amount')->label(__('Amount'))->money('DZD')->weight('bold'),
                        TextEntry::make('paid_at')->label(__('Paid at'))->dateTime(),
                        TextEntry::make('financialAccount.name')->label(__('Account'))->placeholder('—'),
                        TextEntry::make('external_reference')
                            ->label(fn (Payment $record): string => match ($record->method) {
                                PaymentMethod::BankTransfer => __('payments.fields.rib'),
                                PaymentMethod::Ccp => __('payments.fields.ccp_account'),
                                PaymentMethod::BaridiMob => __('payments.fields.baridimob_number'),
                                PaymentMethod::Cheque => __('payments.fields.cheque_number'),
                                PaymentMethod::Card => __('payments.fields.card_reference'),
                                default => __('payments.fields.external_reference'),
                            })
                            ->placeholder('—'),
                        TextEntry::make('cheque_due_date')
                            ->label(__('Cheque due'))
                            ->date()
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make(__('payments.fields.paid_for'))
                    ->schema([
                        TextEntry::make('payable')
                            ->label(__('payments.fields.paid_for'))
                            ->state(fn (Payment $record): string => $this->payableLink($record)['label'] ?? '—')
                            ->url(fn (Payment $record): ?string => $this->payableLink($record)['url']),
                        TextEntry::make('customer_id')
                            ->label(__('payments.fields.customer'))
                            ->formatStateUsing(fn (Payment $record): string => $record->customer?->displayName() ?? '—')
                            ->url(fn (Payment $record): ?string => $record->customer !== null && CustomerResource::canAccess()
                                ? CustomerResource::getUrl('view', ['record' => $record->customer])
                                : null),
                        TextEntry::make('receivedBy.name')->label(__('Received by'))->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make(__('Notes'))
                    ->schema([
                        TextEntry::make('notes')->label('')->placeholder('—')->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    /**
     * Resolve the morph to a label and a link the viewer may actually open — a
     * dead link into a 403 is worse than plain text. Memoised: the infolist
     * reads it twice (state and url), and the morph would otherwise load twice.
     *
     * @return array{label: ?string, url: ?string}
     */
    private function payableLink(Payment $record): array
    {
        if ($this->payableCache !== null) {
            return $this->payableCache;
        }

        $payable = $record->payable;

        if ($payable === null) {
            return $this->payableCache = ['label' => null, 'url' => null];
        }

        // The morph resolves to a bare Model, so the label goes through the
        // attribute bag rather than a property that only some payables declare.
        $label = (string) ($payable->getAttribute('reference') ?? $payable->getKey());

        $resource = match ($record->payable_type) {
            Booking::class => BookingResource::class,
            Contract::class => ContractResource::class,
            Expense::class => ExpenseResource::class,
            Fine::class => FineResource::class,
            OwnerInstallment::class => OwnerInstallmentResource::class,
            Deposit::class => DepositResource::class,
            default => null,
        };

        $url = null;

        if ($resource !== null
            && method_exists($resource, 'canAccess') && $resource::canAccess()
            && isset($resource::getPages()['view'])) {
            $url = $resource::getUrl('view', ['record' => $payable]);
        }

        return $this->payableCache = ['label' => $label, 'url' => $url];
    }

    /**
     * The payment's ledger postings: the E10–E14 entry that recorded it and
     * anything that reverses it, strictly read-only (ADR-003). A reversed
     * payment must show its reversal here, or it would look settled.
     *
     * @return array<int, mixed>
     */
    protected function getAllRelationManagers(): array
    {
        return [
            RelationGroup::make(__('Postings'), [
                TransactionsRelationManager::class,
            ]),
        ];
    }
}
