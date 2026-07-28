<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id',
        'base_salary', 'commissions_amount', 'bonuses_amount', 'overtime_amount',
        'advances_deducted', 'absences_deduction', 'social_contributions', 'other_deductions',
        'gross_amount', 'net_amount',
        'status', 'payment_id', 'paid_at', 'notes',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
