<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FineResource\Pages;

use App\Filament\Admin\Resources\FineResource;
use App\Models\Fine;
use Filament\Resources\Pages\EditRecord;

class EditFine extends EditRecord
{
    protected static string $resource = FineResource::class;

    /**
     * Same guard as create: the row's decision belongs to the assign action,
     * which posts the ledger entry. Whatever the form sends — a crafted
     * payload included — the liability and status ride back unchanged, so the
     * row can never claim a decision the ledger did not record.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Fine $record */
        $record = $this->record;

        $data['liability'] = $record->liability->value;
        $data['status'] = $record->status->value;

        return $data;
    }
}
