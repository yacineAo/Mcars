<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToBranch, LogsActivity, SoftDeletes;

    protected $fillable = [
        'user_id', 'branch_id', 'employee_number',
        'first_name', 'last_name', 'national_id',
        'date_of_birth', 'phone', 'address',
        'job_title', 'department',
        'hire_date', 'termination_date', 'termination_reason',
        'contract_type', 'salary_type', 'base_salary',
        'commission_scheme',
        'bank_rib', 'ccp_account', 'social_security_number',
        'status', 'notes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }
}
