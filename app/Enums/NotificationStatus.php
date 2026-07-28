<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of one delivery attempt on one channel.
 *
 * `Queued` counts as "already alerted" for deduplication — a row exists the moment
 * the decision to notify is made, and the ADR-012 check must see it. Counting only
 * `Sent` would let the hourly run re-queue the same alert while the first is still
 * on the queue.
 */
enum NotificationStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getColor(): string
    {
        return match ($this) {
            self::Queued, self::Sending => 'gray',
            self::Sent => 'info',
            self::Delivered => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Queued => 'heroicon-o-clock',
            self::Sending => 'heroicon-o-paper-airplane',
            self::Sent => 'heroicon-o-check',
            self::Delivered => 'heroicon-o-check-badge',
            self::Failed => 'heroicon-o-x-circle',
            self::Cancelled => 'heroicon-o-no-symbol',
        };
    }

    /**
     * Statuses that occupy the dedup window.
     *
     * Anything not yet ruled out still represents an alert the recipient will see,
     * so it must suppress a duplicate. Failed and Cancelled deliberately do not —
     * a send that never landed should be allowed to alert again.
     *
     * @return list<self>
     */
    public static function occupiesDedupWindow(): array
    {
        return [self::Queued, self::Sending, self::Sent, self::Delivered];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Cancelled], strict: true);
    }
}
