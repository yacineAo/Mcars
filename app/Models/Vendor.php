<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VendorType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use BelongsToBranch, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'name',
        'type',
        'contact_name',
        'phone',
        'email',
        'address',
        'notes',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'type' => VendorType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<MaintenanceLog> */
    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class);
    }

    /** @return HasMany<Expense> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
