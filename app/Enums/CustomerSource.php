<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum CustomerSource: string implements HasIcon, HasLabel
{
    use HasEnumMeta;

    case WalkIn = 'walk_in';
    case Referral = 'referral';
    case Website = 'website';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Partner = 'partner';
    case Other = 'other';

    public function getIcon(): string
    {
        return match ($this) {
            self::WalkIn => 'heroicon-o-user',
            self::Referral => 'heroicon-o-share',
            self::Website => 'heroicon-o-globe-alt',
            self::Facebook => 'heroicon-o-chat-bubble-left-ellipsis',
            self::Instagram => 'heroicon-o-camera',
            self::Partner => 'heroicon-o-handshake',
            self::Other => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }
}
