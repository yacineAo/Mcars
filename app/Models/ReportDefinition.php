<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ReportType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
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
    use BelongsToBranch, HasAuditColumns, SoftDeletes;

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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PendingExport, $this> */
    public function pendingExports(): HasMany
    {
        return $this->hasMany(PendingExport::class);
    }

    public function hasRuns(): bool
    {
        return $this->pendingExports()->exists();
    }

    /**
     * The next time the cron fires, strictly after $from.
     *
     * Null when no cron is set. The schedule is evaluated in the app timezone
     * (Africa/Algiers) because RunScheduledReports compares against
     * CarbonImmutable::now() — the same evaluation this method uses.
     */
    public function nextRunAt(?CarbonImmutable $from = null): ?CarbonImmutable
    {
        if ($this->schedule_cron === null) {
            return null;
        }

        return self::runTimes($this->schedule_cron, 1, $from)[0] ?? null;
    }

    /**
     * The next $count run times, strictly after $from.
     *
     * @return list<CarbonImmutable>
     */
    public function nextRunTimes(int $count = 3, ?CarbonImmutable $from = null): array
    {
        if ($this->schedule_cron === null) {
            return [];
        }

        return self::runTimes($this->schedule_cron, $count, $from);
    }

    /**
     * The next $count run times of a cron expression, strictly after $from.
     *
     * @return list<CarbonImmutable>
     */
    public static function runTimes(string $cron, int $count = 3, ?CarbonImmutable $from = null): array
    {
        $expression = new CronExpression($cron);
        $current = $from ?? CarbonImmutable::now()->addMinute();
        $times = [];

        for ($i = 0; $i < $count; $i++) {
            $current = CarbonImmutable::instance($expression->getNextRunDate($current));
            $times[] = $current;
        }

        return $times;
    }

    /**
     * The schedule recipients, split on commas and filtered to valid addresses.
     *
     * The stored value is a comma-separated list, because a monthly P&L usually
     * goes to more than one person and a second, linked table of recipients would
     * be furniture for a screen that fits in one field.
     *
     * @return list<string>
     */
    public function scheduleEmailRecipients(): array
    {
        if ($this->schedule_email === null || $this->schedule_email === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $this->schedule_email)),
            static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }
}
