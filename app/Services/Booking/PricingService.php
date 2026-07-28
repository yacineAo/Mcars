<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\Car;
use App\Models\ConditionReport;
use App\Models\Extra;
use Carbon\CarbonPeriod;

class PricingService
{
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

        if ($checkin->odometer !== null && $booking->odometer_out !== null) {
            $kmDriven = $checkin->odometer - $booking->odometer_out;
            $allowedKm = $booking->days_count * 100;
            if ($kmDriven > $allowedKm) {
                $extraKm = $kmDriven - $allowedKm;
                $extraKmFee = number_format($extraKm * 5, 2, '.', '');
            }
        }

        $now = now();
        if ($booking->expected_return_at && $now->isAfter($booking->expected_return_at)) {
            $lateHours = (int) ceil($booking->expected_return_at->diffInHours($now));
            $lateFee = number_format($lateHours * ((float) $booking->daily_rate / 24), 2, '.', '');
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
