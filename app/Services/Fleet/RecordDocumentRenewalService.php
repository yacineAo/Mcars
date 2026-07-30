<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\CarDocumentType;
use App\Models\CarDocument;
use App\Models\FinancialAccount;
use App\Models\Transaction;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\MaintenancePoster;
use App\Support\Money;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Owns "a car document was renewed at a cost" — posting E42.
 *
 * Kept separate from the document's own create/update path on purpose. A `car_document` row
 * is also written for documents that cost nothing to file and for back-filling paperwork
 * that was paid for years ago; posting on every save would invent expenses. So the posting
 * is an explicit act, and this service is the only thing that performs it.
 *
 * Idempotency is enforced by looking for an existing E42 row against the same document,
 * because the ledger is append-only (ADR-003) — a double post is corrected by a reversal,
 * never by a delete.
 */
class RecordDocumentRenewalService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountingService $accounting,
        private readonly MaintenancePoster $poster,
    ) {}

    /**
     * @param FinancialAccount|null $account Paid from this account now; null leaves the
     *                                       cost on credit against 2210 AP–Suppliers.
     */
    public function record(CarDocument $document, ?FinancialAccount $account, int $userId): Transaction
    {
        if (! Money::of($document->cost ?? '0')->isPositive()) {
            throw new RuntimeException('This document has no cost recorded, so there is nothing to post.');
        }

        if (! $this->isPostable($document->type)) {
            throw new RuntimeException(
                'A '.$document->type->getLabel().' has no expense account in the posting matrix.',
            );
        }

        if ($this->alreadyPosted($document)) {
            throw new RuntimeException('This document renewal has already been posted to the ledger.');
        }

        return $this->db->transaction(fn (): Transaction => $this->accounting->post(
            $this->poster->postDocumentRenewed($document, $account, $userId),
        ));
    }

    /**
     * Renew a document: create the replacement, mark the old one as superseded, and
     * optionally post the renewal cost (E42 / E42b / E42c) if the cost is positive.
     * All three writes happen inside one database transaction.
     *
     * @param array{number: string, issuer?: ?string, issue_date: string, expiry_date: string, cost?: string} $data
     */
    public function renew(CarDocument $oldDocument, array $data, ?FinancialAccount $account, int $userId): CarDocument
    {
        return $this->db->transaction(function () use ($oldDocument, $data, $account, $userId): CarDocument {
            // Guard against concurrent renewals: re-read the row with a lock so that
            // a second request entering this transaction sees the committed write
            // (or waits for the first transaction's lock to release).
            $locked = CarDocument::lockForUpdate()->findOrFail($oldDocument->id);

            if ($locked->replaced_by_id !== null) {
                throw new RuntimeException('This document has already been renewed.');
            }

            $newDocument = CarDocument::create([
                'car_id' => $oldDocument->car_id,
                'type' => $oldDocument->type,
                'number' => $data['number'],
                'issuer' => $data['issuer'] ?? $oldDocument->issuer,
                'issue_date' => $data['issue_date'],
                'expiry_date' => $data['expiry_date'],
                'cost' => $data['cost'] ?? 0,
                'reminder_days_before' => $oldDocument->reminder_days_before,
            ]);

            $oldDocument->update(['replaced_by_id' => $newDocument->id]);

            $cost = Money::of($newDocument->cost ?? '0');
            if ($cost->isPositive() && $this->isPostable($newDocument->type)) {
                $this->accounting->post(
                    $this->poster->postDocumentRenewed($newDocument, $account, $userId),
                );
            }

            return $newDocument;
        });
    }

    /**
     * Whether a live (non-reversed) E42 row exists for this document.
     *
     * A table showing this per row must **not** call this per record — that is one query a
     * row. Prefetch with `CarDocument::withPostedToLedger()` and read
     * `$document->posted_to_ledger` instead; this method is for the single-document write
     * path, where one query is the point.
     */
    public function alreadyPosted(CarDocument $document): bool
    {
        if ($document->getAttribute('posted_to_ledger') !== null) {
            return (bool) $document->getAttribute('posted_to_ledger');
        }

        return $document->ledgerTransactions()->where('is_reversal', false)->exists();
    }

    /**
     * Whether the matrix has an expense account for this document type at all. An ownership
     * title or a purchase invoice is acquisition paperwork, not a running cost.
     */
    public function isPostable(CarDocumentType $type): bool
    {
        return in_array($type, [
            CarDocumentType::Insurance,
            CarDocumentType::RegistrationCard,
            CarDocumentType::RoadTaxVignette,
            CarDocumentType::TechnicalInspection,
            CarDocumentType::GpsSubscription,
        ], strict: true);
    }
}
