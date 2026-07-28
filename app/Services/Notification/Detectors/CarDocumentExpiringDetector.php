<?php

declare(strict_types=1);

namespace App\Services\Notification\Detectors;

use App\Enums\AlertType;
use App\Enums\CarDocumentType;
use App\Models\AlertRule;
use App\Models\Car;
use App\Models\CarDocument;
use App\Services\Notification\AlertSubject;
use App\Services\Notification\Contracts\AlertDetector;
use App\Services\Notification\SubjectLabel;
use Carbon\CarbonImmutable;

/**
 * Insurance, registration, technical inspection and the rest of the car paperwork.
 *
 * `car_documents.reminder_days_before` predates alert rules and is set per
 * document. Rather than ignore it, the effective lead time is the greater of the
 * two — a document explicitly configured to warn 60 days out keeps doing so under
 * a 30-day rule, and the rule still raises the floor for everything else.
 */
final class CarDocumentExpiringDetector implements AlertDetector
{
    public function type(): AlertType
    {
        return AlertType::CarDocumentExpiring;
    }

    public function detect(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $query = CarDocument::query()
            ->with('car')
            ->whereNotNull('expiry_date')
            ->whereNull('replaced_by_id')
            // Already-expired documents stay in scope: the paperwork is still
            // missing, and going quiet the day it lapses is the wrong behaviour.
            ->whereRaw(
                'expiry_date <= (?::date + make_interval(days => GREATEST(?, COALESCE(reminder_days_before, 0))))',
                [$now->toDateString(), $rule->days_before],
            );

        if ($rule->branch_id !== null) {
            $query->whereHas('car', fn ($q) => $q->where('branch_id', $rule->branch_id));
        }

        foreach ($query->lazyById(100) as $document) {
            $expiry = CarbonImmutable::parse((string) $document->expiry_date);
            /** @var Car|null $car */
            $car = $document->car;
            /** @var CarDocumentType $documentType */
            $documentType = $document->type;

            yield new AlertSubject(
                subject: $document,
                branchId: $car?->branch_id,
                payload: [
                    'car' => SubjectLabel::car($car),
                    'document_type' => $documentType->getLabel(),
                    'number' => $document->number ?? '—',
                    'expiry_date' => $expiry->translatedFormat('d/m/Y'),
                    'days_remaining' => (int) $now->startOfDay()->diffInDays($expiry, false),
                ],
            );
        }
    }
}
