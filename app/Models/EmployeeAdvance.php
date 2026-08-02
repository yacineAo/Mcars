<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdvanceStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAdvance extends Model
{
    use BelongsToBranch, LogsActivity;

    protected $fillable = [
        'employee_id', 'branch_id', 'amount', 'advanced_on', 'reason',
        'financial_account_id', 'payment_id',
        'status', 'recovered_in_payroll_item_id',
    ];

    protected $casts = [
        'status' => AdvanceStatus::class,
        'amount' => 'decimal:2',
        'advanced_on' => 'date',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<PayrollItem, $this> */
    public function recoveredInPayrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'recovered_in_payroll_item_id');
    }
}
