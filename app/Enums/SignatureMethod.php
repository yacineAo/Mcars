<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasLabel;

enum SignatureMethod: string implements HasLabel
{
    use HasEnumMeta;

    case Drawn = 'drawn';
    case Otp = 'otp';
    case UploadedScan = 'uploaded_scan';
    case InPersonPaper = 'in_person_paper';
}
