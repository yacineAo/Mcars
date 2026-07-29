<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\LogsActivity;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One delivery attempt on one channel to one recipient.
 *
 * This is both the audit trail and the deduplication source of truth: the ADR-012
 * check reads this table, which is why a row is written the moment the decision to
 * notify is taken — before the queue worker touches it.
 *
 * No soft deletes. A deleted delivery record would silently reopen the dedup
 * window for a subject that was in fact already alerted.
 *
 * @property int $id
 * @property int|null $branch_id
 * @property int|null $alert_rule_id
 * @property NotificationChannel $channel
 * @property string $template_key
 * @property int|null $user_id
 * @property string $recipient
 * @property string $locale
 * @property string|null $related_type
 * @property int|null $related_id
 * @property array<string, mixed>|null $payload
 * @property NotificationStatus $status
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property int $attempts
 * @property string $cost
 * @property-read AlertRule|null        $alertRule
 * @property-read User|null             $user
 */
class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use BelongsToBranch, HasFactory, LogsActivity;

    protected $fillable = [
        'branch_id',
        'alert_rule_id',
        'channel',
        'template_key',
        'user_id',
        'recipient',
        'locale',
        'related_type',
        'related_id',
        'payload',
        'status',
        'provider',
        'provider_message_id',
        'error',
        'attempts',
        'cost',
        'queued_at',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    public function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => NotificationStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'cost' => 'decimal:2',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The subject the alert is about — a Booking, a CarDocument, and so on.
     *
     * @return MorphTo<Model, $this>
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('related_type', $subject->getMorphClass())
            ->where('related_id', $subject->getKey());
    }

    /**
     * Rows that count as "already alerted" for deduplication.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeOccupyingDedupWindow(Builder $query): Builder
    {
        return $query->whereIn('status', array_column(
            NotificationStatus::occupiesDedupWindow(),
            'value',
        ));
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', NotificationStatus::Failed->value);
    }

    public function markSending(): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Sending,
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    /** @param  array<string, mixed>  $meta */
    public function markSent(string $provider, ?string $providerMessageId = null, array $meta = []): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Sent,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
            'error' => null,
            'cost' => $meta['cost'] ?? $this->cost,
        ])->save();
    }

    public function markDelivered(): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Delivered,
            'delivered_at' => now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => NotificationStatus::Failed,
            // Postgres rejects a text value containing a NUL byte, and provider
            // error bodies are not guaranteed clean. Losing the log row to an
            // encoding error would hide the very failure it records.
            'error' => str_replace("\0", '', mb_substr($error, 0, 2000)),
            'failed_at' => now(),
        ])->save();
    }
}
