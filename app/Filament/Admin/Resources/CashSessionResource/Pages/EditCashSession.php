<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CashSessionResource\Pages;

use App\Filament\Admin\Resources\CashSessionResource;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;

class EditCashSession extends EditRecord
{
    protected static string $resource = CashSessionResource::class;

    /**
     * A session's identity is its account, its float and when it opened —
     * changing any of them after cash has moved silently changes the expected
     * balance and therefore the variance. Notes are the only editable field.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Textarea::make('notes')
                    ->maxLength(65535),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
