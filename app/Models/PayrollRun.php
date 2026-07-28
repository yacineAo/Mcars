<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id', 'period_month', 'status',
        'approved_by_id', 'approved_at', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
