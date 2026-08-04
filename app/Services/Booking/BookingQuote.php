<?php

declare(strict_types=1);

namespace App\Services\Booking;

final readonly class BookingQuote
{
    /** @param list<array{extra_id: int, name: string, quantity: int, unit_price: string, total: string}> $extras */
    public function __construct(
        public int $carId,
        public int $customerId,
        public string $dailyRate,
        public int $daysCount,
        public string $subtotal,
        public string $extrasTotal,
        public string $discountAmount,
        public ?string $discountReason,
        public string $totalAmount,
        public string $securityDepositAmount,
        public array $extras = [],
    ) {}
}
