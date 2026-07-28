<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ConditionReportType;
use App\Enums\FuelLevel;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Car;
use App\Models\ConditionReport;
use App\Models\Customer;
use App\Models\User;
use App\Services\Booking\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Closeout charges are billed to the customer, so getting them wrong takes money
 * from someone who does not owe it.
 *
 * Both cases below were live defects, found by seeding realistic demo data:
 *
 * - The late fee was measured against now() instead of the actual return time, so
 *   a rental returned on time and closed out later was billed for every hour in
 *   between. Over 120 days of backdated history that produced 17 million DZD of
 *   phantom late fees across 34 rentals.
 * - The mileage allowance and excess rate were hardcoded to 100 km/day and
 *   5 DZD/km, ignoring the values configured on the car and quoted to the
 *   customer.
 */
beforeEach(function () {
    $this->branch = Branch::factory()->create(['is_default' => true, 'code' => 'MAIN']);
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
    $this->pricing = app(PricingService::class);

    $this->car = Car::factory()->create([
        'branch_id' => $this->branch->id,
        'daily_rate' => '4800.00',
        'mileage_limit_per_day' => 200,
        'extra_km_price' => '25.00',
    ]);

    $this->rental = function (string $due, ?string $returned, int $odoOut = 50000, int $odoIn = 50400): array {
        $booking = Booking::create([
            'branch_id' => $this->branch->id,
            'car_id' => $this->car->id,
            'customer_id' => $this->customer->id,
            'status' => BookingStatus::Completed,
            'pickup_at' => CarbonImmutable::parse($due)->subDays(2),
            'expected_return_at' => $due,
            'actual_return_at' => $returned,
            'odometer_out' => $odoOut,
            'daily_rate' => '4800.00',
            'days_count' => 2,
            'subtotal' => '9600.00',
            'total_amount' => '9600.00',
            'created_by_id' => $this->user->id,
        ]);

        $report = ConditionReport::create([
            'booking_id' => $booking->id,
            'type' => ConditionReportType::Checkin,
            'performed_at' => $returned ?? $due,
            'odometer' => $odoIn,
            'fuel_level' => FuelLevel::Full,
            'is_clean' => true,
            'damage_points' => [],
        ]);

        return [$booking->fresh(), $report];
    };
});

it('charges nothing late when the car came back on time, however long ago', function () {
    // The rental ended four months ago and is only being closed out now. The
    // customer was not late; the office was slow.
    Carbon::setTestNow('2026-08-01 10:00:00');

    [$booking, $report] = ($this->rental)('2026-04-01 18:00:00', '2026-04-01 17:30:00');

    $quote = $this->pricing->closeout($booking, $report);

    expect($quote->lateFee)->toBe('0.00');

    Carbon::setTestNow();
});

it('charges lateness measured to the actual return, not to today', function () {
    Carbon::setTestNow('2026-08-01 10:00:00');

    // Due Friday 18:00, brought back Saturday 06:00 — twelve hours late.
    [$booking, $report] = ($this->rental)('2026-04-03 18:00:00', '2026-04-04 06:00:00');

    $quote = $this->pricing->closeout($booking, $report);

    // 12 hours x (4800 / 24) = 2 400, regardless of when it is processed.
    expect($quote->lateFee)->toBe('2400.00');

    Carbon::setTestNow();
});

it("uses the car's own mileage allowance and excess rate", function () {
    // 2 days x 200 km = 400 allowed; 700 driven leaves 300 excess at 25 DZD.
    [$booking, $report] = ($this->rental)('2026-04-01 18:00:00', '2026-04-01 17:00:00', 50000, 50700);

    $quote = $this->pricing->closeout($booking, $report);

    expect($quote->extraKmFee)->toBe('7500.00');
});

it('charges no excess mileage within the allowance', function () {
    [$booking, $report] = ($this->rental)('2026-04-01 18:00:00', '2026-04-01 17:00:00', 50000, 50350);

    expect($this->pricing->closeout($booking, $report)->extraKmFee)->toBe('0.00');
});

it('falls back to the inspection time when actual_return_at is not set', function () {
    Carbon::setTestNow('2026-08-01 10:00:00');

    // Due yesterday at 10:00, inspected today at 10:00 — a day late, and the
    // booking row has not recorded the return time yet.
    [$booking, $report] = ($this->rental)('2026-07-31 10:00:00', null);
    $booking->update(['actual_return_at' => null]);
    $report->update(['performed_at' => '2026-08-01 10:00:00']);

    $quote = $this->pricing->closeout($booking->fresh(), $report->fresh());

    // 24 hours x (4800 / 24) = 4 800.
    expect($quote->lateFee)->toBe('4800.00');

    Carbon::setTestNow();
});
