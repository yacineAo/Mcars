<?php

declare(strict_types=1);

namespace App\Services\Booking;

final readonly class CloseoutQuote
{
    public function __construct(
        public int $bookingId,
        public string $extraKmFee,
        public string $fuelShortfall,
        public string $lateFee,
        public string $cleaningFee,
        public string $total,
    ) {}
}
