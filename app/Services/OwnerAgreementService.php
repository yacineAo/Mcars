<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AgreementModel;
use App\Enums\AgreementStatus;
use App\Models\Car;
use App\Models\CarOwner;
use App\Models\CarOwnershipAgreement;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class OwnerAgreementService
{
    /**
     * @param array<string, mixed> $data
     */
    public function createAgreement(CarOwner $owner, array $data): CarOwnershipAgreement
    {
        $this->validateModelAmounts($data);

        $car = Car::findOrFail($data['car_id']);

        $this->ensureNoOverlap($car, $data);

        try {
            return CarOwnershipAgreement::create([
                'car_owner_id' => $owner->id,
                'car_id' => $data['car_id'],
                'branch_id' => $owner->branch_id,
                'model' => $data['model'],
                'monthly_rent_amount' => $data['monthly_rent_amount'] ?? 0,
                'share_percentage' => $data['share_percentage'] ?? 0,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'payment_day_of_month' => $data['payment_day_of_month'] ?? null,
                'installments_count' => $data['installments_count'] ?? null,
                'first_due_date' => $data['first_due_date'] ?? null,
                'grace_days' => $data['grace_days'] ?? 0,
                'status' => AgreementStatus::Active->value,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'agreements_no_overlap')) {
                throw ValidationException::withMessages([
                    'car_id' => __('This car already has an active agreement for the selected period.'),
                ]);
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateModelAmounts(array $data): void
    {
        $model = $data['model'] ?? null;

        if ($model === AgreementModel::FixedMonthly->value || $model === AgreementModel::Hybrid->value) {
            if (empty($data['monthly_rent_amount']) || (float) $data['monthly_rent_amount'] <= 0) {
                throw ValidationException::withMessages([
                    'monthly_rent_amount' => __('The monthly rent amount is required for :model agreements.', ['model' => $model]),
                ]);
            }
        }

        if ($model === AgreementModel::RevenueShare->value || $model === AgreementModel::Hybrid->value) {
            if (empty($data['share_percentage']) || (float) $data['share_percentage'] <= 0) {
                throw ValidationException::withMessages([
                    'share_percentage' => __('The share percentage is required for :model agreements.', ['model' => $model]),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ensureNoOverlap(Car $car, array $data): void
    {
        $start = $data['start_date'];
        $end = $data['end_date'] ?? null;

        $existing = CarOwnershipAgreement::where('car_id', $car->id)
            ->where('status', AgreementStatus::Active->value)
            ->where(function ($query) use ($start, $end): void {
                // Match daterange '[)' semantics: [existing_start, existing_end) ⋂ [start, end)
                // Overlap when: existing.start < new.end AND (existing.end IS NULL OR existing.end > new.start)
                if ($end !== null) {
                    $query->where('start_date', '<', $end);
                }

                $query->where(function ($q) use ($start): void {
                    $q->whereNull('end_date')
                        ->orWhere('end_date', '>', $start);
                });
            })
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'car_id' => __('This car already has an active agreement for the selected period.'),
            ]);
        }
    }
}
