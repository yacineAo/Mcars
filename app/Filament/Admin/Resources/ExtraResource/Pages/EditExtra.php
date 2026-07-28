<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExtraResource\Pages;

use App\Filament\Admin\Resources\ExtraResource;
use Filament\Resources\Pages\EditRecord;

class EditExtra extends EditRecord
{
    protected static string $resource = ExtraResource::class;
}
