<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FineLiability;
use App\Enums\FineStatus;
use App\Enums\FineType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasLedgerPostings;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $branch_id
 * @property int|null $car_id
 * @property int|null $booking_id
 * @property int|null $contract_id
 * @property int|null $customer_id
 * @property FineType $type
 * @property string $reference
 * @property string|null $authority
 * @property string|null $notice_number
 * @property CarbonInterface|null $violation_at
 * @property string|null $location
 * @property CarbonInterface|null $received_at
 * @property CarbonInterface|null $due_date
 * @property string $amount
 * @property string $late_penalty_amount
 * @property string $total_amount
 * @property FineLiability $liability
 * @property FineStatus $status
 */
class Fine extends Model
{
    use BelongsToBranch, HasLedgerPostings, LogsActivity;

    protected $fillable = [
        'reference', 'branch_id', 'car_id',
        'booking_id', 'contract_id', 'customer_id',
        'type', 'authority', 'notice_number',
        'violation_at', 'location', 'received_at', 'due_date',
        'amount', 'late_penalty_amount', 'total_amount',
        'liability', 'liability_determined_by_id', 'liability_determined_at', 'liability_note',
        'status', 'paid_at', 'payment_id', 'notes',
    ];

    /**
     * This model had no casts, so `status`, `liability` and every money and date
     * field came back as raw strings — the same class of defect that made payments
     * fail to post.
     */
    protected function casts(): array
    {
        return [
            'type' => FineType::class,
            'status' => FineStatus::class,
            'liability' => FineLiability::class,
            'amount' => 'decimal:2',
            'late_penalty_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'violation_at' => 'datetime',
            'received_at' => 'datetime',
            'due_date' => 'date',
            'liability_determined_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Car, $this> */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function liabilityDeterminedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liability_determined_by_id');
    }
}
