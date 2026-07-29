<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $name
 * @property array<string, mixed> $parameters
 * @property ExportFormat $format
 * @property ReportType $report_type
 * @property string|null $schedule_cron
 * @property string|null $schedule_email
 * @property bool $schedule_enabled
 * @property CarbonImmutable|null $last_sent_at
 */
class ReportDefinition extends Model
{
    use BelongsToBranch, HasAuditColumns, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'user_id',
        'name',
        'report_type',
        'format',
        'parameters',
        'schedule_cron',
        'schedule_email',
        'schedule_enabled',
        'last_sent_at',
    ];

    public function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ExportFormat::class,
            'parameters' => 'json',
            'schedule_enabled' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pendingExports(): HasMany
    {
        return $this->hasMany(PendingExport::class);
    }
}
