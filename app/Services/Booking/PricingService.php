<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Car;
use App\Models\ConditionReport;
use App\Models\Extra;
use App\Support\Money;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

class PricingService
{
    /** Fallbacks for a car with no configured allowance. */
    private const int DEFAULT_KM_PER_DAY = 200;

    private const float DEFAULT_EXTRA_KM_PRICE = 25.0;

    public function __construct(
        private readonly string $depositRate = '0.30',
    ) {}

    /** @param list<int> $extraIds */
    public function quote(
        Car $car,
        CarbonPeriod $period,
        array $extraIds = [],
        ?string $discountAmount = null,
        ?string $discountReason = null,
    ): BookingQuote {
        $days = (int) ceil($period->getStartDate()->diffInDays($period->getEndDate()));
        $dailyRate = $car->daily_rate;
        $subtotal = Money::of($dailyRate)->times($days);

        $extrasTotal = Money::zero();
        $extras = [];

        if (! empty($extraIds)) {
            $extraModels = Extra::whereIn('id', $extraIds)->where('is_active', true)->get();
            foreach ($extraModels as $extra) {
                $qty = 1;
                $price = $extra->unit_price;
                $total = $extra->pricing_unit === 'per_day'
                    ? Money::of($price)->times($days)
                    : Money::of($price);
                $extras[] = [
                    'extra_id' => $extra->id,
                    'name' => $extra->name,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'total' => $total->toDecimal(),
                ];
                $extrasTotal = $extrasTotal->plus($total);
            }
        }

        $discountAmount ??= '0.00';
        // No tax is charged, so the total is simply what is left after the
        // discount. The deposit follows the total, as before.
        $totalAmount = $subtotal->plus($extrasTotal)->minus(Money::of($discountAmount));
        $depositAmount = $totalAmount->times($this->depositRate);

        return new BookingQuote(
            carId: $car->id,
            customerId: 0,
            dailyRate: $dailyRate,
            daysCount: $days,
            subtotal: $subtotal->toDecimal(),
            extrasTotal: $extrasTotal->toDecimal(),
            discountAmount: $discountAmount,
            discountReason: $discountReason,
            totalAmount: $totalAmount->toDecimal(),
            securityDepositAmount: $depositAmount->toDecimal(),
            extras: $extras,
        );
    }

    public function closeout(Booking $booking, ConditionReport $checkin): CloseoutQuote
    {
        $extraKmFee = Money::zero();
        $fuelShortfall = Money::zero();
        $lateFee = Money::zero();
        $cleaningFee = Money::zero();

        /** @var Car|null $car */
        $car = $booking->car;
        /** @var CarbonInterface|null $dueAt */
        $dueAt = $booking->expected_return_at;

        if ($checkin->odometer !== null && $booking->odometer_out !== null) {
            $kmDriven = $checkin->odometer - $booking->odometer_out;

            // The allowance and the excess rate belong to the car, not to a
            // constant. Hardcoding them billed every vehicle at 100 km/day and
            // 5 DZD/km regardless of what the customer was actually quoted.
            $perDay = (int) ($car->mileage_limit_per_day ?? self::DEFAULT_KM_PER_DAY);
            $rate = $car->extra_km_price ?? self::DEFAULT_EXTRA_KM_PRICE;
            $allowedKm = $booking->days_count * $perDay;

            if ($kmDriven > $allowedKm) {
                $extraKmFee = Money::of($rate)->times($kmDriven - $allowedKm);
            }
        }

        // Lateness is measured to when the car actually came back — NOT to now().
        // Using the wall clock billed the delay between the due time and whenever
        // someone got round to closing the rental, so an on-time return processed
        // the next morning was charged a full day late.
        /** @var CarbonInterface $returnedAt */
        $returnedAt = $booking->actual_return_at ?? $checkin->performed_at ?? now();

        if ($dueAt !== null && $returnedAt->greaterThan($dueAt)) {
            $lateHours = (int) ceil($dueAt->diffInHours($returnedAt));

            // A per-hour rate on the car overrides the pro-rata daily rate, since
            // a late hour costs more than a rented one.
            $carHourlyRate = $car->late_hour_fee !== null ? Money::of($car->late_hour_fee) : null;
            $hourlyRate = $carHourlyRate?->isPositive() === true
                ? $carHourlyRate
                : Money::of($booking->daily_rate)->dividedBy(24);

            $lateFee = $hourlyRate->times($lateHours);
        }

        $fuelLevelIn = $checkin->fuel_level;
        $fuelLevelOut = $booking->fuel_level_out;
        if ($fuelLevelIn && $fuelLevelOut) {
            $fuelMap = ['full' => 5, 'three_quarters' => 4, 'half' => 3, 'quarter' => 2, 'empty' => 1];
            $outVal = $fuelMap[$fuelLevelOut->value];
            $inVal = $fuelMap[$fuelLevelIn->value];
            if ($inVal < $outVal) {
                $fuelShortfall = Money::of(1000)->times($outVal - $inVal);
            }
        }

        if (! $checkin->is_clean) {
            $cleaningFee = Money::of('3000.00');
        }

        $total = $extraKmFee->plus($fuelShortfall)->plus($lateFee)->plus($cleaningFee);

        return new CloseoutQuote(
            bookingId: $booking->id,
            extraKmFee: $extraKmFee->toDecimal(),
            fuelShortfall: $fuelShortfall->toDecimal(),
            lateFee: $lateFee->toDecimal(),
            cleaningFee: $cleaningFee->toDecimal(),
            total: $total->toDecimal(),
        );
    }
}
