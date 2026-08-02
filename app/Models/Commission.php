<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    use BelongsToBranch, LogsActivity;

    protected $fillable = [
        'employee_id', 'booking_id', 'contract_id', 'branch_id',
        'basis_amount', 'rate', 'amount',
        'status', 'payroll_item_id', 'earned_on', 'notes',
    ];

    protected $casts = [
        'status' => CommissionStatus::class,
        'basis_amount' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'earned_on' => 'date',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

    /** The payroll item that swept this commission in; null until paid. */
    /** @return BelongsTo<PayrollItem, $this> */
    public function payrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }
}
