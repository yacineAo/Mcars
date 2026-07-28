<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertType;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasAuditColumns;
use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One configured alert: what to watch, how far ahead, how often to repeat, on
 * which channels, to whom.
 *
 * A branch-scoped rule (branch_id set) overrides the global rule of the same type
 * for that branch — see resolveFor().
 *
 * @property int $id
 * @property int|null $branch_id
 * @property AlertType $type
 * @property string $template_key
 * @property int $days_before
 * @property int|null $repeat_every_days
 * @property int|null $max_repeats
 * @property list<string> $channels
 * @property list<string> $recipient_roles
 * @property bool $is_active
 */
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use BelongsToBranch, HasAuditColumns, HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'type',
        'template_key',
        'days_before',
        'repeat_every_days',
        'max_repeats',
        'channels',
        'recipient_roles',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'type' => AlertType::class,
            'days_before' => 'integer',
            'repeat_every_days' => 'integer',
            'max_repeats' => 'integer',
            'channels' => 'array',
            'recipient_roles' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Alert rules opt out of branch auto-fill.
     *
     * On an operational row a null branch_id is a gap to be filled. Here it is
     * meaningful data: null means "applies to every branch". Letting
     * BelongsToBranch fill it from the acting user would silently convert every
     * global rule into a rule for whichever branch the admin happened to belong
     * to — and the other branches would then get no alerts at all.
     */
    protected static function resolveBranchId(): ?int
    {
        return null;
    }

    /** @return HasMany<NotificationLog, $this> */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, AlertType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * The channels this rule delivers on, as enums.
     *
     * Unknown values are dropped rather than thrown on: a channel retired from the
     * enum should not stop the remaining channels of an otherwise valid rule.
     *
     * @return list<NotificationChannel>
     */
    public function channelEnums(): array
    {
        $values = array_filter((array) $this->channels, 'is_string');

        return array_values(array_filter(array_map(
            static fn (string $value): ?NotificationChannel => NotificationChannel::tryFrom($value),
            $values,
        )));
    }

    /** @return list<UserRole> */
    public function recipientRoleEnums(): array
    {
        $values = array_filter((array) $this->recipient_roles, 'is_string');

        return array_values(array_filter(array_map(
            static fn (string $value): ?UserRole => UserRole::tryFrom($value),
            $values,
        )));
    }

    /**
     * Whether this rule may still alert about a subject that has already been
     * alerted `$timesAlready` times.
     *
     * A null max_repeats means unlimited; the repeat interval alone bounds it.
     */
    public function allowsAnotherRepeat(int $timesAlready): bool
    {
        if ($this->max_repeats === null) {
            return true;
        }

        return $timesAlready < $this->max_repeats;
    }

    /**
     * The rule governing $type for $branchId: the branch's own rule if it has one,
     * otherwise the global rule. Returns null when neither exists or both are off.
     */
    public static function resolveFor(AlertType $type, ?int $branchId): ?self
    {
        return static::query()
            ->active()
            ->ofType($type)
            ->where(fn (Builder $q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            // A branch-specific rule sorts before the global one, so first() picks
            // the override when it exists. NULLS LAST is required — Postgres sorts
            // NULLs first on ASC by default, which would pick the global rule.
            ->orderByRaw('branch_id NULLS LAST')
            ->first();
    }
}
