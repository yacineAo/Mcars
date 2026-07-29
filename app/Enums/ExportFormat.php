<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumMeta;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ExportFormat: string implements HasColor, HasIcon, HasLabel
{
    use HasEnumMeta;

    case Pdf = 'pdf';
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public function getColor(): string
    {
        return match ($this) {
            self::Pdf => 'danger',
            self::Xlsx => 'success',
            self::Csv => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pdf => 'heroicon-o-document-text',
            self::Xlsx => 'heroicon-o-table-cells',
            self::Csv => 'heroicon-o-document-arrow-down',
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv',
        };
    }

    public function extension(): string
    {
        return $this->value;
    }
}
