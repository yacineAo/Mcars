<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Car;
use App\Models\ConditionReport;
use App\Models\Extra;
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

    public function quote(
        Car $car,
        CarbonPeriod $period,
        array $extraIds = [],
        ?string $discountAmount = null,
        ?string $discountReason = null,
    ): BookingQuote {
        $days = (int) ceil($period->getStartDate()->diffInDays($period->getEndDate()));
        $dailyRate = $car->daily_rate;
        $subtotal = number_format((float) $dailyRate * $days, 2, '.', '');

        $extrasTotal = '0.00';
        $extras = [];

        if (! empty($extraIds)) {
            $extraModels = Extra::whereIn('id', $extraIds)->where('is_active', true)->get();
            foreach ($extraModels as $extra) {
                $qty = 1;
                $price = $extra->unit_price;
                if ($extra->pricing_unit === 'per_day') {
                    $total = number_format((float) $price * $days, 2, '.', '');
                } elseif ($extra->pricing_unit === 'per_km') {
                    $total = $price;
                } else {
                    $total = $price;
                }
                $extras[] = [
                    'extra_id' => $extra->id,
                    'name' => $extra->name,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'total' => $total,
                ];
                $extrasTotal = number_format((float) $extrasTotal + (float) $total, 2, '.', '');
            }
        }

        $discountAmount ??= '0.00';
        // No tax is charged, so the total is simply what is left after the
        // discount. The deposit follows the total, as before.
        $totalAmount = number_format((float) $subtotal + (float) $extrasTotal - (float) $discountAmount, 2, '.', '');
        $depositAmount = number_format((float) $totalAmount * (float) $this->depositRate, 2, '.', '');

        return new BookingQuote(
            carId: $car->id,
            customerId: 0,
            dailyRate: $dailyRate,
            daysCount: $days,
            subtotal: $subtotal,
            extrasTotal: $extrasTotal,
            discountAmount: $discountAmount,
            discountReason: $discountReason,
            totalAmount: $totalAmount,
            securityDepositAmount: $depositAmount,
            extras: $extras,
        );
    }

    public function closeout(Booking $booking, ConditionReport $checkin): CloseoutQuote
    {
        $extraKmFee = '0.00';
        $fuelShortfall = '0.00';
        $lateFee = '0.00';
        $cleaningFee = '0.00';

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
            $rate = (float) ($car->extra_km_price ?? self::DEFAULT_EXTRA_KM_PRICE);
            $allowedKm = $booking->days_count * $perDay;

            if ($kmDriven > $allowedKm) {
                $extraKmFee = number_format(($kmDriven - $allowedKm) * $rate, 2, '.', '');
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
            $hourly = (float) ($car->late_hour_fee ?? 0) > 0
                ? (float) $car->late_hour_fee
                : (float) $booking->daily_rate / 24;

            $lateFee = number_format($lateHours * $hourly, 2, '.', '');
        }

        $fuelLevelIn = $checkin->fuel_level;
        $fuelLevelOut = $booking->fuel_level_out;
        if ($fuelLevelIn && $fuelLevelOut) {
            $fuelMap = ['full' => 5, 'three_quarters' => 4, 'half' => 3, 'quarter' => 2, 'empty' => 1];
            $outVal = $fuelMap[$fuelLevelOut->value] ?? 3;
            $inVal = $fuelMap[$fuelLevelIn->value] ?? 3;
            if ($inVal < $outVal) {
                $fuelShortfall = number_format(($outVal - $inVal) * 1000, 2, '.', '');
            }
        }

        if (! $checkin->is_clean) {
            $cleaningFee = '3000.00';
        }

        $total = number_format(
            (float) $extraKmFee + (float) $fuelShortfall + (float) $lateFee + (float) $cleaningFee,
            2, '.', '',
        );

        return new CloseoutQuote(
            bookingId: $booking->id,
            extraKmFee: $extraKmFee,
            fuelShortfall: $fuelShortfall,
            lateFee: $lateFee,
            cleaningFee: $cleaningFee,
            total: $total,
        );
    }
}
