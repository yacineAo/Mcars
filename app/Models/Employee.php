<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeStatus;
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

    /**
     * Without these, `status`, `base_salary` and every date came back as raw
     * strings — the same class of defect that made payments fail to post.
     */
    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'base_salary' => 'decimal:2',
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'termination_date' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PayrollItem, $this> */
    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /** @return HasMany<EmployeeAdvance, $this> */
    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }
}
