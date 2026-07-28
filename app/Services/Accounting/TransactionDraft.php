<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\PaymentMethod;
use App\Enums\TransactionType;
use DateTimeImmutable;

final readonly class TransactionDraft
{
    public function __construct(
        public int $debitAccountId,
        public int $creditAccountId,
        public string $amount,
        public TransactionType $type,
        public DateTimeImmutable $occurredOn,
        public ?string $description = null,
        public ?int $branchId = null,
        public string $currency = 'DZD',
        public ?int $carId = null,
        public ?int $bookingId = null,
        public ?int $contractId = null,
        public ?int $customerId = null,
        public ?int $carOwnerId = null,
        public ?int $employeeId = null,
        public ?int $expenseCategoryId = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?int $createdById = null,
        public ?int $cashSessionId = null,
        public ?PaymentMethod $paymentMethod = null,
        public ?int $reversesTransactionId = null,
        public bool $isReversal = false,
        public ?array $meta = null,
    ) {}
}
