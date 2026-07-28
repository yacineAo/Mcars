<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\CarDocument;

class CarDocumentObserver
{
    public function created(CarDocument $document): void
    {
        $this->syncMirrorFields($document);
    }

    public function updated(CarDocument $document): void
    {
        $this->syncMirrorFields($document);
    }

    public function deleted(CarDocument $document): void
    {
        $this->recalculateMirrorFields($document);
    }

    private function syncMirrorFields(CarDocument $document): void
    {
        $car = $document->car;
        if (! $car) {
            return;
        }

        $field = match ($document->type->value) {
            'insurance' => 'insurance_expiry_date',
            'technical_inspection' => 'technical_inspection_expiry_date',
            'registration_card' => 'registration_expiry_date',
            'road_tax_vignette' => 'road_tax_expiry_date',
            default => null,
        };

        if (! $field) {
            return;
        }

        $latest = CarDocument::query()
            ->where('car_id', $car->id)
            ->where('type', $document->type->value)
            ->whereNotNull('expiry_date')
            ->orderByDesc('expiry_date')
            ->value('expiry_date');

        $car->updateQuietly([$field => $latest]);
    }

    private function recalculateMirrorFields(CarDocument $document): void
    {
        $car = $document->car;
        if (! $car) {
            return;
        }

        $field = match ($document->type->value) {
            'insurance' => 'insurance_expiry_date',
            'technical_inspection' => 'technical_inspection_expiry_date',
            'registration_card' => 'registration_expiry_date',
            'road_tax_vignette' => 'road_tax_expiry_date',
            default => null,
        };

        if (! $field) {
            return;
        }

        $latest = CarDocument::query()
            ->where('car_id', $car->id)
            ->where('type', $document->type->value)
            ->whereNotNull('expiry_date')
            ->orderByDesc('expiry_date')
            ->value('expiry_date');

        $car->updateQuietly([$field => $latest]);
    }
}
