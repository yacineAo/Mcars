<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Delivery channels with a registered driver in config/notifications.php.
 *
 * A case only exists here once a driver backs it — the column carries a CHECK
 * constraint, so an unbacked case would let a row be written that no driver can
 * ever deliver. Adding WhatsApp or SMS later is one case, one driver class and a
 * migration widening the constraint; no calling code changes.
 */
enum NotificationChannel: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Database = 'database';
    case Mail = 'mail';
    case Discord = 'discord';

    public function getColor(): string
    {
        return match ($this) {
            self::Database => 'gray',
            self::Mail => 'info',
            self::Discord => 'primary',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Database => 'heroicon-o-bell',
            self::Mail => 'heroicon-o-envelope',
            self::Discord => 'heroicon-o-chat-bubble-left-right',
        };
    }

    /** In-app notifications are local; the rest leave the building. */
    public function isExternal(): bool
    {
        return $this !== self::Database;
    }
}
