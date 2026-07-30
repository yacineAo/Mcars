<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\CarDocumentType;
use App\Enums\TransactionType;
use App\Models\CarDocument;
use App\Models\ChartOfAccount;
use App\Models\FinancialAccount;
use App\Models\MaintenanceLog;
use App\Support\Money;
use DateTimeImmutable;
use RuntimeException;

/**
 * Postings E41, E42, E42b and E42c from docs/05-accounting-model.md.
 *
 * | #    | Event                                     | Dr                         | Cr                         | Dimensions                   |
 * |------|-------------------------------------------|----------------------------|----------------------------|------------------------------|
 * | E41  | Maintenance completed                     | 5040 Maintenance & Repairs | 1010/1020/1030 or 2210     | car, vendor, maintenance_log |
 * | E42  | Insurance renewed                         | 5050 Insurance             | 1010/1020/1030 or 2210     | car, car_document            |
 * | E42b | Registration / vignette / inspection      | 5060 Taxes & Registration  | 1010/1020/1030 or 2210     | car, car_document            |
 * | E42c | GPS subscription renewed for a car        | 5100 Internet & Telecom    | 1010/1020/1030 or 2210     | car, car_document            |
 *
 * E42c carries `car_id` even though E47's row for 5100 reads "branch only" — E47 is office
 * internet, which belongs to a branch; a GPS subscription is billed per tracked car and must
 * reach per-car profitability. The matrix states both rows so neither has to be inferred.
 *
 * Both exist so that a car's Expenses and Net Profit on the car page include what the
 * workshop and the insurer cost. Before these, every 5040 row in the ledger came from a
 * hand-entered `Expense`, so per-car profitability was overstated by the whole repair bill.
 *
 * This class only builds drafts. `AccountingService` is the sole writer to `transactions`.
 */
class MaintenancePoster
{
    /**
     * E41 — a completed maintenance log. Paid now against a financial account, or left on
     * credit against 2210 AP–Suppliers when no account is given.
     */
    public function postMaintenanceCompleted(
        MaintenanceLog $log,
        ?FinancialAccount $account,
        int $userId,
    ): TransactionDraft {
        $car = $log->car;

        if ($car === null) {
            throw new RuntimeException("Maintenance log [{$log->id}] has no car; E41 needs a car dimension.");
        }

        if ($log->completed_at === null) {
            throw new RuntimeException("Maintenance log [{$log->id}] is not completed.");
        }

        return new TransactionDraft(
            debitAccountId: $this->accountId('5040'),
            creditAccountId: $account !== null
                ? (int) $account->ledger_account_id
                : $this->accountId('2210'),
            amount: (string) $log->total_cost,
            type: TransactionType::Maintenance,
            // The accounting date is the day the work was completed, not the day someone
            // got round to recording it.
            occurredOn: new DateTimeImmutable($log->completed_at->format('Y-m-d')),
            description: $this->maintenanceDescription($log),
            branchId: $log->branch_id ?? $car->branch_id,
            carId: $car->id,
            sourceType: 'maintenance_log',
            sourceId: $log->id,
            createdById: $userId,
            paymentMethod: null,
            meta: [
                'vendor_id' => $log->vendor_id,
                'maintenance_type' => $log->type->value,
                'invoice_number' => $log->invoice_number,
                'odometer_at_service' => $log->odometer_at_service,
            ],
        );
    }

    /**
     * E42 / E42b / E42c — a car document renewed at a cost. The expense account and the
     * transaction type both follow `car_documents.type`; see the posting matrix, where each
     * of the three account choices has its own row.
     */
    public function postDocumentRenewed(
        CarDocument $document,
        ?FinancialAccount $account,
        int $userId,
    ): TransactionDraft {
        $car = $document->car;

        if ($car === null) {
            throw new RuntimeException("Car document [{$document->id}] has no car; E42 needs a car dimension.");
        }

        $cost = Money::of($document->cost ?? '0');

        if (! $cost->isPositive()) {
            throw new RuntimeException("Car document [{$document->id}] has no cost to post.");
        }

        // The accounting date is the day the document was issued. Falling back to
        // `created_at` would date a back-filled renewal on the day someone typed it in,
        // which lands last year's premium in this month's expenses.
        if ($document->issue_date === null) {
            throw new RuntimeException(
                "Car document [{$document->id}] has no issue date; E42 cannot pick an accounting date.",
            );
        }

        return new TransactionDraft(
            debitAccountId: $this->accountId($this->documentExpenseCode($document->type)),
            creditAccountId: $account !== null
                ? (int) $account->ledger_account_id
                : $this->accountId('2210'),
            amount: $cost->toDecimal(),
            type: $this->documentTransactionType($document->type),
            occurredOn: new DateTimeImmutable($document->issue_date->format('Y-m-d')),
            description: $this->documentDescription($document),
            branchId: $car->branch_id,
            carId: $car->id,
            sourceType: 'car_document',
            sourceId: $document->id,
            createdById: $userId,
            meta: [
                'document_type' => $document->type->value,
                'document_number' => $document->number,
                'expiry_date' => $document->expiry_date?->format('Y-m-d'),
            ],
        );
    }

    /** E42 → 5050, E42b → 5060, E42c → 5100. */
    private function documentExpenseCode(CarDocumentType $type): string
    {
        return match ($type) {
            CarDocumentType::Insurance => '5050',
            CarDocumentType::RegistrationCard,
            CarDocumentType::RoadTaxVignette,
            CarDocumentType::TechnicalInspection => '5060',
            CarDocumentType::GpsSubscription => '5100',
            // An ownership title or a purchase invoice is paperwork for an acquisition,
            // not a recurring running cost; there is no sanctioned posting for it.
            CarDocumentType::OwnershipTitle,
            CarDocumentType::PurchaseInvoice,
            CarDocumentType::Other => throw new RuntimeException(
                "Document type [{$type->value}] has no expense account in the posting matrix.",
            ),
        };
    }

    /**
     * Spelled out per case rather than "insurance or else Expense", so the ledger can be
     * filtered by what the money was actually for. `Insurance` has its own case;
     * registration, inspection and a GPS subscription are all recurring running costs with
     * no dedicated type, so they share `Expense`.
     */
    private function documentTransactionType(CarDocumentType $type): TransactionType
    {
        return match ($type) {
            CarDocumentType::Insurance => TransactionType::Insurance,
            CarDocumentType::RegistrationCard,
            CarDocumentType::RoadTaxVignette,
            CarDocumentType::TechnicalInspection,
            CarDocumentType::GpsSubscription => TransactionType::Expense,
            CarDocumentType::OwnershipTitle,
            CarDocumentType::PurchaseInvoice,
            CarDocumentType::Other => throw new RuntimeException(
                "Document type [{$type->value}] has no posting in the matrix.",
            ),
        };
    }

    private function maintenanceDescription(MaintenanceLog $log): string
    {
        $parts = array_filter([
            $log->type->getLabel(),
            $log->car?->registration_number,
            $log->invoice_number !== null ? 'inv. '.$log->invoice_number : null,
        ]);

        return 'Maintenance: '.implode(' — ', $parts);
    }

    private function documentDescription(CarDocument $document): string
    {
        $parts = array_filter([
            $document->type->getLabel(),
            $document->car?->registration_number,
            $document->number !== null ? 'no. '.$document->number : null,
        ]);

        return 'Document renewal: '.implode(' — ', $parts);
    }

    private function accountId(string $code): int
    {
        $id = ChartOfAccount::where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Chart of account [{$code}] is missing; run ChartOfAccountSeeder.");
        }

        return (int) $id;
    }
}
